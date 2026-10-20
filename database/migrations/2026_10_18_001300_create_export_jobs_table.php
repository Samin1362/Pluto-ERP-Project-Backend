<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `export_jobs` (Platform): migration 13 of 83, dependency level 2.
 * Asynchronous export (month-end pack, report exports).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Asynchronous export (month-end pack, report exports).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('kind', 30)->comment('One of: export_pack, report');
            $table->json('parameters');
            $table->string('status', 30)->default('queued')->comment('One of: queued, running, complete, failed');
            $table->string('file_path', 255)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'status'], 'export_jobs_company_id_status_index');
            $table->index(['created_by'], 'export_jobs_created_by_index');
            $table->index(['updated_by'], 'export_jobs_updated_by_index');

            $table->foreign('company_id', 'export_jobs_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'export_jobs_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'export_jobs_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `export_jobs`
                    ADD CONSTRAINT `export_jobs_kind_check` CHECK (`kind` IN ('export_pack', 'report')),
                    ADD CONSTRAINT `export_jobs_status_check` CHECK (`status` IN ('queued', 'running', 'complete', 'failed'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
