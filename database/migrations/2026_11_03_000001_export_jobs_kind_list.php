<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand-written (not generated): P14.1-BE-05 adds the `list` export kind (any list screen, every page). New databases
 * get it from the generated create migration; this widens the CHECK on databases created before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->kinds(['export_pack', 'report', 'list']);
    }

    public function down(): void
    {
        DB::table('export_jobs')->where('kind', 'list')->delete();
        $this->kinds(['export_pack', 'report']);
    }

    /** @param list<string> $kinds */
    private function kinds(array $kinds): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $exists = DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = 'export_jobs' AND CONSTRAINT_NAME = 'export_jobs_kind_check'")->n;
        if ($exists) {
            DB::statement('ALTER TABLE `export_jobs` DROP CHECK `export_jobs_kind_check`');
        }
        $list = implode(', ', array_map(fn ($k) => "'{$k}'", $kinds));
        DB::statement("ALTER TABLE `export_jobs` ADD CONSTRAINT `export_jobs_kind_check` CHECK (`kind` IN ({$list}))");
    }
};
