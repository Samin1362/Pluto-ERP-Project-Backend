<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): P17.1-BE-02 (TC-NFR-001/002 at 1 M journal lines). New databases get both indexes from
 * the generated create migrations; this converts databases created before:
 * - journal_lines (company_id, ledger_id, is_posted, entry_date) becomes covering with debit and credit, so ledger
 *   balances, the trial balance and P&L read the index only. The new index is created before the old one is dropped:
 *   the company_id foreign key needs an index that starts with company_id at every moment.
 * - journal_entries (company_id, entry_date) serves the voucher list's date-then-id order without a sort, and
 *   (company_id, entry_date, status) becomes (company_id, status, entry_date): the drafts beside the trial balance
 *   read only the drafts (the new date index is created first; company_id's foreign key keeps an index throughout).
 */
return new class extends Migration
{
    private const OLD = 'journal_lines_company_id_ledger_id_is_posted_entry_date_index';

    private const COVERING = 'journal_lines_company_id_ledger_id_is_posted_entr_e3dfddfc_index';

    private const ENTRY_DATE = 'journal_entries_company_id_entry_date_index';

    private const DATE_STATUS = 'journal_entries_company_id_entry_date_status_index';

    private const STATUS_DATE = 'journal_entries_company_id_status_entry_date_index';

    public function up(): void
    {
        if (! $this->has('journal_lines', self::COVERING)) {
            DB::statement('CREATE INDEX '.self::COVERING.' ON journal_lines (company_id, ledger_id, is_posted, entry_date, debit, credit)');
        }
        if ($this->has('journal_lines', self::OLD)) {
            DB::statement('DROP INDEX '.self::OLD.' ON journal_lines');
        }
        if (! $this->has('journal_entries', self::ENTRY_DATE)) {
            DB::statement('CREATE INDEX '.self::ENTRY_DATE.' ON journal_entries (company_id, entry_date)');
        }
        if (! $this->has('journal_entries', self::STATUS_DATE)) {
            DB::statement('CREATE INDEX '.self::STATUS_DATE.' ON journal_entries (company_id, status, entry_date)');
        }
        if ($this->has('journal_entries', self::DATE_STATUS)) {
            DB::statement('DROP INDEX '.self::DATE_STATUS.' ON journal_entries');
        }
    }

    public function down(): void
    {
        if (! $this->has('journal_lines', self::OLD)) {
            DB::statement('CREATE INDEX '.self::OLD.' ON journal_lines (company_id, ledger_id, is_posted, entry_date)');
        }
        if ($this->has('journal_lines', self::COVERING)) {
            DB::statement('DROP INDEX '.self::COVERING.' ON journal_lines');
        }
        if (! $this->has('journal_entries', self::DATE_STATUS)) {
            DB::statement('CREATE INDEX '.self::DATE_STATUS.' ON journal_entries (company_id, entry_date, status)');
        }
        if ($this->has('journal_entries', self::STATUS_DATE)) {
            DB::statement('DROP INDEX '.self::STATUS_DATE.' ON journal_entries');
        }
        if ($this->has('journal_entries', self::ENTRY_DATE)) {
            DB::statement('DROP INDEX '.self::ENTRY_DATE.' ON journal_entries');
        }
    }

    private function has(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $index);
    }
};
