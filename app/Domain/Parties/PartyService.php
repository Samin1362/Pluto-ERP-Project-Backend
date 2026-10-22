<?php

namespace App\Domain\Parties;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Models\AccountMapping;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\PartyGroup;
use App\Support\Problem\ApiProblem;
use App\Support\Problem\ErrorCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Customer and supplier master data (B1-B3, C1-C3). Each party gets its own ledger under the mapped
 * Sundry debtors / Sundry creditors group (06 §3) and a never-reset code (CUS-0001 / SUP-0001, 04 §11.2).
 */
final class PartyService
{
    private const AUDITED = ['name', 'category', 'party_group_id', 'contact_person', 'mobile', 'email', 'alt_phone', 'address',
        'district', 'area', 'country_code', 'default_currency_code', 'payment_term_id', 'credit_limit', 'price_tier',
        'default_purchase_ledger_id', 'tax_registration_no', 'port_of_loading', 'notes', 'status'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{party: Party, warnings: list<array>} */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $type = $data['party_type'];
            $mappingKey = $type === 'customer' ? 'sundry_debtors_group' : 'sundry_creditors_group';
            $mapping = AccountMapping::where('mapping_key', $mappingKey)->first()
                ?? throw new ApiProblem('mapping_missing', "Map {$mappingKey} in Accounting defaults first.");
            $groupCode = DB::table('account_groups')->where('id', $mapping->account_group_id)->value('code');

            $seq = $this->numbers->next($type);
            $ledger = Ledger::create([
                'account_group_id' => $mapping->account_group_id,
                'code' => $groupCode.'-'.str_pad((string) $seq['sequence'], 3, '0', STR_PAD_LEFT),
                'name' => $data['name'], 'ledger_kind' => 'party', 'track_bills' => true, 'is_active' => true,
            ]);
            $party = new Party(collect($data)->except(['bank_accounts', 'opening_balance', 'opening_side'])->all());
            $party->code = $seq['number'];
            $party->ledger_id = $ledger->id;
            $party->status ??= 'active';
            $party->save();
            $this->syncBankAccounts($party, $data['bank_accounts'] ?? null);
            $this->audit->record('created', $party, null, $party->only(self::AUDITED) + ['code' => $party->code]);

            return ['party' => $party->refresh(), 'warnings' => $this->duplicateWarnings($party)];
        });
    }

    public function update(Party $party, array $data, int $expectedVersion): Party
    {
        return DB::transaction(function () use ($party, $data, $expectedVersion) {
            $party = Party::whereKey($party->id)->lockForUpdate()->firstOrFail();
            if ((int) $party->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $party->lock_version]);
            }
            if (isset($data['party_type']) && $data['party_type'] !== $party->party_type) {
                throw new ApiProblem('validation_failed', errors: ['party_type' => ['A customer cannot become a supplier; add a new party instead.']]);
            }
            $old = $party->only(self::AUDITED);
            $party->fill(collect($data)->except(['party_type', 'bank_accounts', 'opening_balance', 'opening_side'])->all());
            if ($party->isDirty('name')) {
                Ledger::whereKey($party->ledger_id)->first()?->update(['name' => $party->name]);
            }
            $party->save();
            if (array_key_exists('bank_accounts', $data)) {
                $this->syncBankAccounts($party, $data['bank_accounts']);
            }
            $this->audit->record('updated', $party, array_diff_assoc($old, $party->only(self::AUDITED)) ?: $old,
                array_diff_assoc($party->only(self::AUDITED), $old));

            return $party->refresh();
        });
    }

    public function setStatus(Party $party, string $status): Party
    {
        return DB::transaction(function () use ($party, $status) {
            $party = Party::whereKey($party->id)->lockForUpdate()->firstOrFail();
            if ($party->status !== $status) {
                $old = $party->status;
                $party->status = $status;
                $party->save();
                $this->audit->record($status === 'inactive' ? 'deactivated' : 'reactivated', $party, ['status' => $old], ['status' => $status]);
            }

            return $party;
        });
    }

    /** "Add group…" in the B2/C2 group select (backlog P2.1-BE-02); the request checks the name is unused. */
    public function createGroup(array $data): PartyGroup
    {
        return DB::transaction(function () use ($data) {
            $group = PartyGroup::create(['party_type' => $data['party_type'], 'name' => $data['name']]);
            $this->audit->record('created', $group, null, $group->only(['party_type', 'name']));

            return $group;
        });
    }

    /**
     * "Mark inactive" for several parties (B1/C1, backlog P2.1-BE-03; ERR-5): each id on its own, so one failure never
     * undoes the others. Ids of other companies or deleted parties fail as not_found; already inactive ones succeed.
     *
     * @param  list<int>  $ids
     * @return array{succeeded: list<int>, failed: list<array{id: int, code: string, detail: string}>}
     */
    public function bulkDeactivate(array $ids): array
    {
        $out = ['succeeded' => [], 'failed' => []];
        foreach ($ids as $id) {
            try {
                $party = Party::find($id) ?? throw new ApiProblem('not_found');
                $this->setStatus($party, 'inactive');
                $out['succeeded'][] = $id;
            } catch (ApiProblem $e) {
                $out['failed'][] = ['id' => $id, 'code' => $e->errorCode, 'detail' => $e->detail ?? ErrorCatalogue::title($e->errorCode)];
            }
        }

        return $out;
    }

    /** BR-026: only a party without history can be deleted (soft delete); otherwise make it inactive. */
    public function delete(Party $party): void
    {
        DB::transaction(function () use ($party) {
            $party = Party::whereKey($party->id)->lockForUpdate()->firstOrFail();
            if ($this->hasHistory($party)) {
                throw new ApiProblem('has_history', "This {$party->party_type} has transactions; mark it inactive instead.");
            }
            Ledger::whereKey($party->ledger_id)->first()?->update(['is_active' => false]);
            $party->delete();
            $this->audit->record('deleted', $party, ['code' => $party->code, 'name' => $party->name]);
        });
    }

    public function hasHistory(Party $party): bool
    {
        if (DB::table('journal_lines')->where('company_id', $party->company_id)->where('ledger_id', $party->ledger_id)->exists()) {
            return true;
        }
        foreach (['quotations', 'sales_invoices', 'sales_returns', 'credit_notes', 'purchase_orders', 'purchase_bills', 'purchase_returns',
            'debit_notes', 'letters_of_credit', 'payment_details'] as $table) {
            if (DB::table($table)->where('company_id', $party->company_id)->where('party_id', $party->id)->exists()) {
                return true;
            }
        }

        return DB::table('lc_charges')->where('company_id', $party->company_id)->where('payee_party_id', $party->id)->exists();
    }

    private function syncBankAccounts(Party $party, ?array $accounts): void
    {
        if ($accounts === null) {
            return;
        }
        $party->bankAccounts()->delete();
        foreach ($accounts as $a) {
            $party->bankAccounts()->create(collect($a)->only(['bank_name', 'branch', 'account_name', 'account_number',
                'routing_number', 'swift_code', 'is_default'])->all());
        }
    }

    /** Non-blocking DUPLICATE_PARTY warning (BR-015): same name or mobile already on file for this party type. */
    private function duplicateWarnings(Party $party): array
    {
        $dup = Party::where('party_type', $party->party_type)->whereKeyNot($party->id)
            ->where(fn ($q) => $q->where('name', $party->name)->when($party->mobile, fn ($q) => $q->orWhere('mobile', $party->mobile)))
            ->first(['code', 'name']);

        return $dup ? [['code' => 'DUPLICATE_PARTY', 'message' => "Possible duplicate of {$dup->code} {$dup->name}."]] : [];
    }
}
