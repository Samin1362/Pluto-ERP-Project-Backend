<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `money_accounts` (Money): migration 37 of 83, dependency level 4.
 * Cash, bank, mobile-wallet or card account; 1:1 with its ledger.
 * Depends on: companies, currencies, ledgers, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Cash, bank, mobile-wallet or card account; 1:1 with its ledger.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('ledger_id');
            $table->string('account_type', 30)->comment('One of: cash, bank, wallet, card, cash_credit. cash_credit = bank CC/overdraft; its ledger sits under current liabilities (R-13)');
            $table->string('name', 120);
            $table->decimal('overdraft_limit', 18, 2)->nullable()->comment('Bank or cash-credit limit; payments may take the balance down to minus this (BR-031)');
            $table->string('bank_name', 120)->nullable();
            $table->string('branch_name', 120)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->char('routing_number', 9)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('custodian_user_id')->nullable();
            $table->boolean('use_for_receipts')->default(true);
            $table->boolean('use_for_payments')->default(true);
            $table->boolean('show_on_invoices')->default(false);
            $table->date('last_reconciled_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['ledger_id'], 'money_accounts_ledger_id_unique');
            $table->index(['company_id', 'account_type'], 'money_accounts_company_id_account_type_index');
            $table->index(['currency_code'], 'money_accounts_currency_code_index');
            $table->index(['custodian_user_id'], 'money_accounts_custodian_user_id_index');
            $table->index(['created_by'], 'money_accounts_created_by_index');
            $table->index(['updated_by'], 'money_accounts_updated_by_index');

            $table->foreign('company_id', 'money_accounts_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('ledger_id', 'money_accounts_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('currency_code', 'money_accounts_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('custodian_user_id', 'money_accounts_custodian_user_id_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'money_accounts_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'money_accounts_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `money_accounts`
                    ADD CONSTRAINT `money_accounts_account_type_check` CHECK (`account_type` IN ('cash', 'bank', 'wallet', 'card', 'cash_credit')),
                    ADD CONSTRAINT `money_accounts_rule1_check` CHECK (`overdraft_limit` IS NULL OR `overdraft_limit` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('money_accounts');
    }
};
