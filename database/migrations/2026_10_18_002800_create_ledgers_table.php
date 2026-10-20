<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `ledgers` (Accounting): migration 28 of 83, dependency level 3.
 * A posting account. Customers, suppliers, banks, cash and warehouse inventory each get one.
 * Depends on: account_groups, companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A posting account. Customers, suppliers, banks, cash and warehouse inventory each get one.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('account_group_id');
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->string('ledger_kind', 30)->default('general')->comment('One of: general, party, bank, cash, inventory, tax, system');
            $table->boolean('track_bills')->default(false)->comment('Bill-by-bill allocation (parties)');
            $table->boolean('show_in_cash_vouchers')->default(false);
            $table->boolean('is_system')->default(false)->comment('Mapped system ledger: cannot be deleted or regrouped across natures');
            $table->boolean('is_active')->default(true)->comment('Ledgers with entries are deactivated, never deleted');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'code'], 'ledgers_company_id_code_unique');
            $table->index(['company_id', 'ledger_kind'], 'ledgers_company_id_ledger_kind_index');
            $table->index(['company_id', 'name'], 'ledgers_company_id_name_index');
            $table->index(['account_group_id'], 'ledgers_account_group_id_index');
            $table->index(['created_by'], 'ledgers_created_by_index');
            $table->index(['updated_by'], 'ledgers_updated_by_index');

            $table->foreign('company_id', 'ledgers_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('account_group_id', 'ledgers_account_group_id_foreign')->references('id')->on('account_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'ledgers_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'ledgers_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `ledgers`
                    ADD CONSTRAINT `ledgers_ledger_kind_check` CHECK (`ledger_kind` IN ('general', 'party', 'bank', 'cash', 'inventory', 'tax', 'system'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
