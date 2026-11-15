<?php

namespace App\Domain\Dashboard;

use App\Domain\Shared\AuditLogger;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * New-company setup checklist (PEP-511; owner request 15 Nov 2026): the first things a company does in Pluto, each
 * marked done from its own data, never by hand: profile (address), logo, tax rules, a bank or cash account, a
 * warehouse, items, customers, a posted sales invoice, and a second person in the team. The dashboard shows it until
 * every step is done or an Owner hides it (company_settings.setup_hidden_at).
 */
final class SetupChecklistService
{
    public const STEPS = ['profile', 'logo', 'tax', 'money', 'warehouse', 'items', 'customers', 'first_invoice', 'team'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit) {}

    /** @return array{steps: list<array{key: string, done: bool}>, done: int, steps_count: int, hidden_at: ?string} SetupChecklist */
    public function get(): array
    {
        $c = $this->current->id();
        $company = DB::table('companies')->where('id', $c)->first(['registered_address', 'logo_path']);
        $has = fn (string $table, array $where = []) => DB::table($table)->where('company_id', $c)->where($where)->exists();
        $done = [
            'profile' => trim((string) $company->registered_address) !== '',
            'logo' => $company->logo_path !== null,
            'tax' => $has('tax_rules'),
            'money' => $has('money_accounts'),
            'warehouse' => $has('warehouses'),
            'items' => $has('products'),
            'customers' => $has('parties', ['party_type' => 'customer']),
            'first_invoice' => $has('sales_invoices', ['status' => 'posted']),
            'team' => DB::table('company_users')->where('company_id', $c)->where('status', 'active')->where('joined_via', 'member')->count() > 1
                || DB::table('user_invitations')->where('company_id', $c)->whereNull('revoked_at')->exists(),
        ];
        $hidden = DB::table('company_settings')->where('company_id', $c)->value('setup_hidden_at');

        return ['steps' => array_map(fn ($k) => ['key' => $k, 'done' => $done[$k]], self::STEPS), 'done' => count(array_filter($done)),
            'steps_count' => count(self::STEPS), 'hidden_at' => $hidden ? CarbonImmutable::parse($hidden, 'UTC')->toIso8601String() : null];
    }

    /** POST /setup-checklist/hide (settings.edit). */
    public function hide(): array
    {
        $c = $this->current->id();
        if (DB::table('company_settings')->where('company_id', $c)->whereNull('setup_hidden_at')->update(['setup_hidden_at' => now(), 'updated_at' => now()])) {
            $this->audit->record('setup_checklist_hidden', null, null, ['hidden' => true], type: 'company_settings');
        }

        return $this->get();
    }
}
