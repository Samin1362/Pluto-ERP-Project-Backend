<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `company_backups` (Platform): migration 12 of 83, dependency level 2.
 * Per-company logical snapshot (automatic daily, manual, before restore, before period lock).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_backups', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Per-company logical snapshot (automatic daily, manual, before restore, before period lock).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('kind', 30)->comment('One of: automatic, manual, pre_restore, pre_lock');
            $table->string('status', 30)->comment('One of: running, complete, failed, expired');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'started_at'], 'company_backups_company_id_started_at_index');
            $table->index(['created_by'], 'company_backups_created_by_index');
            $table->index(['updated_by'], 'company_backups_updated_by_index');

            $table->foreign('company_id', 'company_backups_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'company_backups_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'company_backups_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `company_backups`
                    ADD CONSTRAINT `company_backups_kind_check` CHECK (`kind` IN ('automatic', 'manual', 'pre_restore', 'pre_lock')),
                    ADD CONSTRAINT `company_backups_status_check` CHECK (`status` IN ('running', 'complete', 'failed', 'expired'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_backups');
    }
};
