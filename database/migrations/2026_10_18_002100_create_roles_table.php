<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `roles` (Access): migration 21 of 83, dependency level 2.
 * A named set of permissions inside one company (Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer, custom).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A named set of permissions inside one company (Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer, custom).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false)->comment('Owner role: cannot be edited or deleted');
            $table->decimal('payment_approval_limit', 18, 2)->nullable()->comment('Payments above this need approval; NULL = no limit, allowed only on the system Owner role (R-10)');
            $table->decimal('journal_approval_limit', 18, 2)->nullable()->comment('Journal vouchers above this need approval; NULL = no limit, allowed only on the system Owner role (R-10)');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'slug'], 'roles_company_id_slug_unique');
            $table->index(['created_by'], 'roles_created_by_index');
            $table->index(['updated_by'], 'roles_updated_by_index');

            $table->foreign('company_id', 'roles_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'roles_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'roles_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `roles`
                    ADD CONSTRAINT `roles_rule1_check` CHECK (`is_system` = 1 OR (`payment_approval_limit` IS NOT NULL AND `journal_approval_limit` IS NOT NULL)),
                    ADD CONSTRAINT `roles_rule2_check` CHECK (`payment_approval_limit` IS NULL OR `payment_approval_limit` >= 0),
                    ADD CONSTRAINT `roles_rule3_check` CHECK (`journal_approval_limit` IS NULL OR `journal_approval_limit` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
