<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): posted journals are immutable in the database too (06 §15, BR-003; backlog
 * P4.1-BE-05, TC-ACC-003). docs/source/build_migrations.py only rewrites 2026_10_18_*_create_*_table.php, so this
 * file survives `make docs`. Needs log_bin_trust_function_creators=1 when binary logging is on (docker-compose.yml).
 *  - journal_lines: no UPDATE or DELETE of a posted line;
 *  - journal_entries: no DELETE of a posted or reversed entry; the only UPDATE of a posted entry is status
 *    posted → reversed (with the bookkeeping columns lock_version / updated_* / updated_at); a reversed entry is final.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_posted_no_update BEFORE UPDATE ON journal_lines FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be changed (BR-003); post a reversal instead';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_posted_no_delete BEFORE DELETE ON journal_lines FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be deleted (BR-003); post a reversal instead';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_posted_no_update BEFORE UPDATE ON journal_entries FOR EACH ROW
            BEGIN
                IF OLD.status = 'reversed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reversed journal entry cannot be changed (BR-003)';
                END IF;
                IF OLD.status = 'posted' AND NOT (NEW.status = 'reversed'
                    AND NEW.company_id <=> OLD.company_id AND NEW.voucher_number <=> OLD.voucher_number AND NEW.entry_type <=> OLD.entry_type
                    AND NEW.entry_date <=> OLD.entry_date AND NEW.total_debit <=> OLD.total_debit AND NEW.total_credit <=> OLD.total_credit
                    AND NEW.fiscal_year_id <=> OLD.fiscal_year_id AND NEW.accounting_period_id <=> OLD.accounting_period_id
                    AND NEW.source_type <=> OLD.source_type AND NEW.source_id <=> OLD.source_id AND NEW.reverses_entry_id <=> OLD.reverses_entry_id
                    AND NEW.branch_id <=> OLD.branch_id AND NEW.narration <=> OLD.narration AND NEW.reference <=> OLD.reference
                    AND NEW.posted_at <=> OLD.posted_at AND NEW.posted_by <=> OLD.posted_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A posted journal entry can only change status to reversed (BR-003)';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_posted_no_delete BEFORE DELETE ON journal_entries FOR EACH ROW
            BEGIN
                IF OLD.status IN ('posted', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries cannot be deleted (BR-003)';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (['journal_lines_posted_no_update', 'journal_lines_posted_no_delete', 'journal_entries_posted_no_update', 'journal_entries_posted_no_delete'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
        }
    }
};
