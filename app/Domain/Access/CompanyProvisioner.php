<?php

namespace App\Domain\Access;

use App\Models\AccountGroup;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Ledger;
use App\Models\NumberSequence;
use App\Models\PaymentTerm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Company bootstrap (04 §11.2), in one transaction: settings, payment terms, head-office branch, roles from the
 * K11 matrix, the default chart of accounts with its account mappings (04 §11.3), the current financial year with
 * 12 periods, the number series, and the Owner membership.
 */
final class CompanyProvisioner
{
    /** code => [name, parent code|null, nature] for groups; primaries carry normal balance and report section. */
    private const GROUPS = [
        '1' => ['Assets', null, 'asset'], '11' => ['Current assets', '1', 'asset'], '1100' => ['Cash and bank', '11', 'asset'],
        '1201' => ['Sundry debtors', '11', 'asset'], '1301' => ['Inventory', '11', 'asset'], '12' => ['Fixed assets', '1', 'asset'],
        '2' => ['Liabilities', null, 'liability'], '21' => ['Current liabilities', '2', 'liability'],
        '2101' => ['Sundry creditors', '21', 'liability'], '22' => ['Long-term liabilities', '2', 'liability'],
        '3' => ['Equity', null, 'equity'], '4' => ['Income', null, 'income'], '41' => ['Sales', '4', 'income'],
        '42' => ['Other income', '4', 'income'], '5' => ['Cost of sales', null, 'expense'], '6' => ['Expenses', null, 'expense'],
        '61' => ['Operating expenses', '6', 'expense'], '62' => ['Depreciation and finance', '6', 'expense'],
    ];

    /** code => [name, group code, ledger_kind] */
    private const LEDGERS = [
        '1101-01' => ['Cash in hand', '1100', 'cash'], '1301-01' => ['Inventory, main warehouse', '1301', 'inventory'],
        '1401' => ['Advances and deposits', '11', 'general'], '1402' => ['Tax receivable', '11', 'tax'],
        '1403' => ['LC margin deposits', '11', 'general'], '1404' => ['Import goods in transit (LC)', '11', 'general'],
        '1405' => ['Stock in transit (between warehouses)', '11', 'general'], '1501' => ['Delivery vans', '12', 'general'],
        '1502' => ['Furniture and fixtures', '12', 'general'], '1503' => ['Office equipment', '12', 'general'],
        '1509' => ['Accumulated depreciation', '12', 'general'], '2201' => ['Salaries payable', '21', 'general'],
        '2202' => ['Accrued expenses', '21', 'general'], '2205' => ['Tax payable', '21', 'tax'], '2301' => ['Bank cash credit', '21', 'general'],
        '2302' => ['LC acceptance liability', '21', 'general'], '9999' => ['Suspense', '21', 'system'],
        '3101' => ['Share capital', '3', 'general'], '3201' => ['Retained earnings', '3', 'system'],
        '3301' => ['Opening balance adjustment', '3', 'system'], '4101' => ['Sales, wholesale', '41', 'general'],
        '4102' => ['Sales, retail counter', '41', 'general'], '4109' => ['Sales returns', '41', 'general'],
        '4110' => ['Discount allowed', '41', 'general'], '4201' => ['Delivery income', '42', 'general'],
        '4302' => ['Interest received', '42', 'general'], '4303' => ['Scrap and carton sales', '42', 'general'],
        '4304' => ['Commission received', '42', 'general'], '4305' => ['Discount received', '42', 'general'],
        '5001' => ['Cost of goods sold', '5', 'system'], '5002' => ['Stock shortage, damage and gains', '5', 'system'],
        '5101' => ['Purchases, non-stock items', '5', 'general'], '5109' => ['Purchase returns, non-stock items', '5', 'general'],
        '6001' => ['Salaries and wages', '61', 'general'], '6101' => ['Rent', '61', 'general'], '6105' => ['Transport and delivery', '61', 'general'],
        '6110' => ['Electricity and utilities', '61', 'general'], '6199' => ['Other expenses', '61', 'general'],
        '6201' => ['Marketing and promotion', '61', 'general'], '6198' => ['Cash short and over', '61', 'system'],
        '6501' => ['Depreciation', '62', 'general'], '6601' => ['Interest on loans', '62', 'general'],
        '6603' => ['Exchange gain or loss', '62', 'system'], '6702' => ['Bank charges', '62', 'system'], '6799' => ['Rounding off', '62', 'system'],
    ];

    /** mapping key => [ 'group'|'ledger', code ] (06 §3) */
    private const MAPPINGS = [
        'cash_bank_group' => ['group', '1100'], 'sundry_debtors_group' => ['group', '1201'], 'inventory_group' => ['group', '1301'],
        'sundry_creditors_group' => ['group', '2101'], 'lc_margin' => ['ledger', '1403'], 'goods_in_transit' => ['ledger', '1404'],
        'stock_in_transit' => ['ledger', '1405'], 'lc_acceptance' => ['ledger', '2302'], 'suspense' => ['ledger', '9999'],
        'retained_earnings' => ['ledger', '3201'], 'opening_balance_adjustment' => ['ledger', '3301'], 'sales_default' => ['ledger', '4101'],
        'sales_returns' => ['ledger', '4109'], 'discount_allowed' => ['ledger', '4110'], 'discount_received' => ['ledger', '4305'],
        'cogs' => ['ledger', '5001'], 'stock_adjustment' => ['ledger', '5002'], 'purchases_non_stock' => ['ledger', '5101'],
        'purchase_returns_non_stock' => ['ledger', '5109'], 'cash_short_over' => ['ledger', '6198'], 'fx_gain_loss' => ['ledger', '6603'],
        'bank_charges' => ['ledger', '6702'], 'rounding' => ['ledger', '6799'],
    ];

    /** document_type => [prefix pattern, padding, reset policy] (04 §11.2; {FY} = calendar year the FY starts) */
    private const SEQUENCES = [
        'sales_invoice' => ['INV-{FY}-', 4], 'quotation' => ['QT-{FY}-', 4], 'sales_return' => ['SR-{FY}-', 4], 'credit_note' => ['CN-{FY}-', 4],
        'purchase_order' => ['PO-{FY}-', 4], 'purchase_bill' => ['BILL-{FY}-', 4], 'purchase_return' => ['PR-{FY}-', 4],
        'debit_note' => ['DN-{FY}-', 4], 'receipt' => ['RV-{FY}-', 4], 'payment' => ['PV-{FY}-', 4], 'journal' => ['JV-{FY}-', 4],
        'contra' => ['CV-{FY}-', 4], 'expense' => ['EXP-{FY}-', 4], 'income' => ['INC-{FY}-', 4], 'stock_adjustment' => ['ADJ-{FY}-', 4],
        'stock_transfer' => ['TRF-{FY}-', 4], 'letter_of_credit' => ['LC-{FY}-', 3], 'challan' => ['DC-{FY}-', 4],
        'system_journal' => ['SJ-{FY}-', 4],
    ];

    /** Approval limits; NULL only for the system Owner role (R-10). Finance journal limit is an assumption (see 15 §6). */
    private const LIMITS = [
        'owner' => [null, null], 'finance' => ['500000.00', '500000.00'], 'accountant' => ['50000.00', '100000.00'],
        'sales' => ['0.00', '0.00'], 'store' => ['0.00', '0.00'], 'viewer' => ['0.00', '0.00'],
    ];

    public function __construct(private readonly CurrentCompany $current) {}

    public function provision(string $legalName, User $owner, ?CarbonImmutable $today = null): Company
    {
        $today ??= CarbonImmutable::now('Asia/Dhaka');

        return DB::transaction(function () use ($legalName, $owner, $today) {
            $company = Company::create([
                'legal_name' => $legalName, 'business_type' => 'private_limited', 'base_currency_code' => 'BDT',
                'fy_start_month' => 7, 'timezone' => 'Asia/Dhaka', 'status' => 'active',
            ]);
            $previous = $this->current->has() ? [$this->current->company(), $this->current->membership()] : null;
            $this->current->set($company, null);
            try {
                $this->seedCompany($company, $owner, $today);
            } finally {
                $previous ? $this->current->set(...$previous) : $this->current->clear();
            }

            return $company;
        });
    }

    private function seedCompany(Company $company, User $owner, CarbonImmutable $today): void
    {
        CompanySetting::create([]);
        foreach ([['Cash', 0], ['Net 7', 7], ['Net 14', 14], ['Net 15', 15], ['Net 30', 30], ['Net 45', 45]] as [$name, $days]) {
            PaymentTerm::create(['name' => $name, 'days' => $days, 'is_default' => $name === 'Net 15']);
        }
        Branch::create(['code' => 'HO', 'name' => 'Head office', 'is_head_office' => true]);

        $permIds = Permission::pluck('id', 'key');
        $ownerRole = null;
        foreach (RoleTemplates::ROLES as $slug => [$name, $perms]) {
            [$pay, $journal] = self::LIMITS[$slug];
            $role = Role::create(['name' => $name, 'slug' => $slug, 'is_system' => $slug === 'owner',
                'payment_approval_limit' => $pay, 'journal_approval_limit' => $journal]);
            $role->permissions()->sync($slug === 'owner' ? $permIds->values()->all() : $permIds->only($perms)->values()->all());
            $ownerRole = $slug === 'owner' ? $role : $ownerRole;
        }
        CompanyUser::create(['user_id' => $owner->id, 'role_id' => $ownerRole->id, 'status' => 'active', 'joined_at' => now()]);

        $groups = [];
        $order = 0;
        foreach (self::GROUPS as $code => [$name, $parent, $nature]) {
            $groups[$code] = AccountGroup::create([
                'parent_id' => $parent ? $groups[$parent]->id : null, 'code' => (string) $code, 'name' => $name, 'nature' => $nature,
                'normal_balance' => in_array($nature, ['asset', 'expense'], true) ? 'debit' : 'credit',
                'report_section' => in_array($nature, ['income', 'expense'], true) ? 'profit_and_loss' : 'balance_sheet',
                'cash_flow_category' => match ((string) $code) { '1100' => 'cash', '12' => 'investing', '22', '3' => 'financing',
                    '11', '1201', '1301', '21', '2101' => 'operating', default => null },
                'is_system' => true, 'used_in_cash_vouchers' => in_array((string) $code, ['1100'], true), 'sort_order' => $order++,
            ]);
        }
        $mappedLedgers = array_column(array_filter(self::MAPPINGS, fn ($m) => $m[0] === 'ledger'), 1);
        $ledgers = [];
        foreach (self::LEDGERS as $code => [$name, $group, $kind]) {
            $ledgers[$code] = Ledger::create([
                'account_group_id' => $groups[$group]->id, 'code' => (string) $code, 'name' => $name, 'ledger_kind' => $kind,
                'show_in_cash_vouchers' => $kind === 'cash', 'is_system' => $kind === 'system' || in_array((string) $code, $mappedLedgers, true),
                'is_active' => true,
            ]);
        }
        foreach (self::MAPPINGS as $key => [$type, $code]) {
            AccountMapping::create(['mapping_key' => $key,
                'ledger_id' => $type === 'ledger' ? $ledgers[$code]->id : null,
                'account_group_id' => $type === 'group' ? $groups[$code]->id : null]);
        }

        // Current financial year (July-June) and its 12 periods; months up to today are open, later ones future.
        $startYear = $today->month >= $company->fy_start_month ? $today->year : $today->year - 1;
        $fyStart = CarbonImmutable::create($startYear, $company->fy_start_month, 1, 0, 0, 0, 'Asia/Dhaka');
        $fyId = DB::table('fiscal_years')->insertGetId([
            'company_id' => $company->id, 'name' => sprintf('FY %d-%02d', $startYear, ($startYear + 1) % 100),
            'start_date' => $fyStart->toDateString(), 'end_date' => $fyStart->addYear()->subDay()->toDateString(),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < 12; $i++) {
            $m = $fyStart->addMonths($i);
            DB::table('accounting_periods')->insert([
                'company_id' => $company->id, 'fiscal_year_id' => $fyId, 'name' => $m->format('M Y'),
                'start_date' => $m->toDateString(), 'end_date' => $m->endOfMonth()->toDateString(),
                'status' => $m->startOfMonth()->lte($today) ? 'open' : 'future', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::SEQUENCES as $type => [$prefix, $padding]) {
            NumberSequence::create(['fiscal_year_id' => $fyId, 'document_type' => $type, 'prefix' => str_replace('{FY}', (string) $startYear, $prefix),
                'next_number' => 1, 'padding' => $padding, 'reset_policy' => 'fiscal_year']);
        }
        foreach (['customer' => 'CUS-', 'supplier' => 'SUP-'] as $type => $prefix) {
            NumberSequence::create(['fiscal_year_id' => null, 'document_type' => $type, 'prefix' => $prefix,
                'next_number' => 1, 'padding' => 4, 'reset_policy' => 'never']);
        }
    }
}
