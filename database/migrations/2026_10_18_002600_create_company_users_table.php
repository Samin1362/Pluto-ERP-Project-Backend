<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `company_users` (Access): migration 26 of 83, dependency level 3.
 * Membership: a user belongs to a company with exactly one role (junction users to companies with payload).
 * Depends on: companies, roles, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_users', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Membership: a user belongs to a company with exactly one role (junction users to companies with payload).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->string('status', 30)->default('invited')->comment('One of: active, invited, deactivated');
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('deactivated_at')->nullable();
            $table->unsignedBigInteger('deactivated_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'user_id'], 'company_users_company_id_user_id_unique');
            $table->index(['user_id'], 'company_users_user_id_index');
            $table->index(['role_id'], 'company_users_role_id_index');
            $table->index(['deactivated_by'], 'company_users_deactivated_by_index');
            $table->index(['created_by'], 'company_users_created_by_index');
            $table->index(['updated_by'], 'company_users_updated_by_index');

            $table->foreign('company_id', 'company_users_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'company_users_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('role_id', 'company_users_role_id_foreign')->references('id')->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('deactivated_by', 'company_users_deactivated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'company_users_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'company_users_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `company_users`
                    ADD CONSTRAINT `company_users_status_check` CHECK (`status` IN ('active', 'invited', 'deactivated'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_users');
    }
};
