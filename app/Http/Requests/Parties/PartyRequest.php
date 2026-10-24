<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;
use App\Models\Party;
use App\Queries\LedgerQuery;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PartyInput (05 §10): create (POST, all required fields) or edit (PATCH, partial).
 * BR-063: Bangladeshi mobile numbers have 11 digits starting 01; mobile is required for parties in BD (R-33).
 * Opening balances post a journal entry (BR-028) and belong to the accounting slice, so only zero is accepted here.
 * A supplier's default purchase ledger must be one the C2 picker offers (purpose purchase_for_supplier, P2.1-BE-01).
 */
class PartyRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['party_type', 'name', 'category', 'party_group_id', 'contact_person', 'mobile', 'email', 'alt_phone', 'address',
            'district', 'area', 'country_code', 'default_currency_code', 'payment_term_id', 'credit_limit', 'price_tier',
            'default_purchase_ledger_id', 'tax_registration_no', 'port_of_loading', 'notes', 'opening_balance', 'opening_side', 'bank_accounts'];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['name', 'contact_person', 'email', 'address', 'district', 'area', 'tax_registration_no', 'port_of_loading'] as $f) {
            if (is_string($this->input($f))) {
                $data[$f] = trim($this->input($f)) === '' ? null : trim($this->input($f));
            }
        }
        if (is_string($this->input('mobile'))) {
            $m = preg_replace('/[\s()]/', '', $this->input('mobile'));
            $data['mobile'] = $m === '' ? null : $m;
        }
        if (is_string($this->input('country_code'))) {
            $data['country_code'] = strtoupper($this->input('country_code'));
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';
        $company = app(CurrentCompany::class)->id();
        $type = $this->input('party_type');
        $country = $this->input('country_code') ?? ($creating ? 'BD' : $this->existingCountry());

        return [
            'party_type' => [$req, Rule::in(['customer', 'supplier'])],
            'name' => [$req, 'string', 'max:190'],
            'category' => ['nullable', Rule::in(['business', 'individual', 'walk_in', 'manufacturer', 'distributor', 'service_provider'])],
            'party_group_id' => ['nullable', 'integer', Rule::exists('party_groups', 'id')->where('company_id', $company)
                ->when($type, fn ($r) => $r->where('party_type', $type))],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'mobile' => [$creating && $country === 'BD' ? 'required' : 'nullable', 'string', 'max:20',
                ...($country === 'BD' || $country === null ? ['regex:/^01\d{3}-?\d{6}$/'] : [])],
            'email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            'alt_phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:60'],
            'area' => ['nullable', 'string', 'max:80'],
            'country_code' => ['sometimes', 'string', 'size:2', 'alpha'],
            'default_currency_code' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $company)],
            'credit_limit' => ['nullable', 'string', 'regex:'.self::MONEY],
            'price_tier' => ['sometimes', Rule::in(['standard', 'wholesale'])],
            'default_purchase_ledger_id' => ['nullable', 'integer', Rule::exists('ledgers', 'id')->where('company_id', $company)->where('is_active', true),
                $this->purchaseLedger($company)],
            'tax_registration_no' => ['nullable', 'string', 'max:40'],
            'port_of_loading' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['sometimes', 'string', 'regex:'.self::MONEY, 'in:0,0.0,0.00'],
            'opening_side' => ['sometimes', Rule::in(['dr', 'cr'])],
            'bank_accounts' => ['sometimes', 'array', 'max:5'],
            'bank_accounts.*' => ['array:id,bank_name,branch,account_name,account_number,routing_number,swift_code,is_default'],
            'bank_accounts.*.bank_name' => ['required', 'string', 'max:120'],
            'bank_accounts.*.branch' => ['nullable', 'string', 'max:120'],
            'bank_accounts.*.account_name' => ['nullable', 'string', 'max:150'],
            'bank_accounts.*.account_number' => ['required', 'string', 'max:40'],
            'bank_accounts.*.routing_number' => ['nullable', 'string', 'max:20'],
            'bank_accounts.*.swift_code' => ['nullable', 'string', 'max:11'],
            'bank_accounts.*.is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** Same set as GET /ledgers?purpose=purchase_for_supplier: an active ledger of an expense group, not a sub-ledger. */
    private function purchaseLedger(?int $company): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($company): void {
            $p = LedgerQuery::PURPOSES['purchase_for_supplier'];
            $ok = DB::table('ledgers')->join('account_groups', 'account_groups.id', '=', 'ledgers.account_group_id')
                ->where('ledgers.company_id', $company)->where('ledgers.id', $value)
                ->whereIn('account_groups.nature', $p['natures'])->whereIn('ledgers.ledger_kind', $p['kinds'])->exists();
            if (! $ok) {
                $fail('Choose an expense ledger for purchases, e.g. 5101 Purchases, non-stock items');
            }
        };
    }

    /** On PATCH without country_code, the stored country decides whether the BD mobile format applies. */
    private function existingCountry(): ?string
    {
        $id = $this->route('id');

        return ctype_digit((string) $id) ? Party::whereKey((int) $id)->value('country_code') : null;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the customer or supplier name',
            'mobile.required' => 'Enter a mobile number',
            'mobile.regex' => 'Mobile numbers have 11 digits starting 01',
            'email.email' => 'Enter a valid e-mail address',
            'credit_limit.regex' => 'Enter an amount with up to 2 decimals, e.g. "500000.00"',
            'opening_balance.in' => 'Opening balances are posted by the accounting module, which is not part of this release; leave it at 0.00',
            'party_group_id.exists' => 'Choose a group of this company and party type',
            'payment_term_id.exists' => 'Choose one of this company\'s payment terms',
        ];
    }

    /** Validated data with mobile normalised to 01XXX-XXXXXX for Bangladeshi numbers. */
    public function partyData(): array
    {
        $data = $this->validated();
        if (! empty($data['mobile']) && preg_match('/^(01\d{3})-?(\d{6})$/', $data['mobile'], $m)) {
            $data['mobile'] = $m[1].'-'.$m[2];
        }
        if ($this->isMethod('POST')) {
            $data += ['country_code' => 'BD', 'default_currency_code' => app(CurrentCompany::class)->company()->base_currency_code, 'price_tier' => 'standard'];
        }

        return $data;
    }
}
