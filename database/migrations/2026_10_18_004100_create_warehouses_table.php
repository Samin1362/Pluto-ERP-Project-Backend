<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `warehouses` (Inventory): migration 41 of 83, dependency level 4.
 * A place that holds stock (godown, depot, outlet, van). Owns its inventory ledger.
 * Depends on: branches, companies, ledgers, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A place that holds stock (godown, depot, outlet, van). Owns its inventory ledger.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('inventory_ledger_id')->comment('Inventory ledger, e.g. 1301-01 Inventory, Tejgaon warehouse');
            $table->boolean('is_default_for_sales')->default(false);
            $table->string('status', 30)->default('active')->comment('One of: active, planned, inactive');
            $table->date('opens_on')->nullable();
            $table->decimal('capacity_used_pct', 7, 4)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'code'], 'warehouses_company_id_code_unique');
            $table->index(['branch_id'], 'warehouses_branch_id_index');
            $table->index(['manager_user_id'], 'warehouses_manager_user_id_index');
            $table->index(['inventory_ledger_id'], 'warehouses_inventory_ledger_id_index');
            $table->index(['created_by'], 'warehouses_created_by_index');
            $table->index(['updated_by'], 'warehouses_updated_by_index');

            $table->foreign('company_id', 'warehouses_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'warehouses_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('manager_user_id', 'warehouses_manager_user_id_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('inventory_ledger_id', 'warehouses_inventory_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'warehouses_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'warehouses_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `warehouses`
                    ADD CONSTRAINT `warehouses_status_check` CHECK (`status` IN ('active', 'planned', 'inactive'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
