<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): a company restore (K15, BR-062; backlog P15.1-BE-09) replaces the company's books with
 * the snapshot, which means deleting its posted journals first. The delete triggers of 2026_11_01_000001 now let that
 * through only for the company named in the session variable @pluto_restore_company, which BackupService sets for the
 * length of the restore transaction and clears afterwards. Everything else stays immutable (BR-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_posted_no_delete');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_posted_no_delete BEFORE DELETE ON journal_lines FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 AND NOT (@pluto_restore_company <=> OLD.company_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be deleted (BR-003); post a reversal instead';
                END IF;
            END
            SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_posted_no_delete');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_posted_no_delete BEFORE DELETE ON journal_entries FOR EACH ROW
            BEGIN
                IF OLD.status IN ('posted', 'reversed') AND NOT (@pluto_restore_company <=> OLD.company_id) THEN
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
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_posted_no_delete');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_posted_no_delete BEFORE DELETE ON journal_lines FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be deleted (BR-003); post a reversal instead';
                END IF;
            END
            SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_posted_no_delete');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_posted_no_delete BEFORE DELETE ON journal_entries FOR EACH ROW
            BEGIN
                IF OLD.status IN ('posted', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries cannot be deleted (BR-003)';
                END IF;
            END
            SQL);
    }
};
