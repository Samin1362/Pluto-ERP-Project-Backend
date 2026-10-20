<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `payment_details` (Money): migration 47 of 83, dependency level 5.
 * Cash/bank detail of a receipt, payment, contra, income or expense entry (1:1 with journal_entries).
 * Depends on: companies, journal_entries, money_accounts, parties, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_details', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Cash/bank detail of a receipt, payment, contra, income or expense entry (1:1 with journal_entries).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('journal_entry_id');
            $table->string('direction', 30)->comment('One of: in, out, transfer');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('payee_text', 150)->nullable()->comment('Payer/payee when not a party');
            $table->unsignedBigInteger('money_account_id');
            $table->unsignedBigInteger('counter_money_account_id')->nullable()->comment('Transfers');
            $table->string('method', 30)->comment('One of: cash, cheque, bank_transfer, beftn, rtgs, npsb, bkash, nagad, card');
            $table->string('cheque_number', 20)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('drawn_on', 120)->nullable();
            $table->string('transaction_reference', 60)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('bank_charge', 18, 2)->default(0);
            $table->string('handed_to', 120)->nullable();
            $table->decimal('unapplied_amount', 18, 2)->default(0)->comment('Advance left on the party ledger');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'payment_details_journal_entry_id_unique');
            $table->index(['company_id', 'money_account_id'], 'payment_details_company_id_money_account_id_index');
            $table->index(['party_id'], 'payment_details_party_id_index');
            $table->index(['money_account_id'], 'payment_details_money_account_id_index');
            $table->index(['counter_money_account_id'], 'payment_details_counter_money_account_id_index');
            $table->index(['created_by'], 'payment_details_created_by_index');
            $table->index(['updated_by'], 'payment_details_updated_by_index');

            $table->foreign('company_id', 'payment_details_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'payment_details_journal_entry_id_foreign')->references('id')->on('journal_entries')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'payment_details_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'payment_details_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('counter_money_account_id', 'payment_details_counter_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'payment_details_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'payment_details_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `payment_details`
                    ADD CONSTRAINT `payment_details_direction_check` CHECK (`direction` IN ('in', 'out', 'transfer')),
                    ADD CONSTRAINT `payment_details_method_check` CHECK (`method` IN ('cash', 'cheque', 'bank_transfer', 'beftn', 'rtgs', 'npsb', 'bkash', 'nagad', 'card')),
                    ADD CONSTRAINT `payment_details_rule1_check` CHECK (`amount` > 0),
                    ADD CONSTRAINT `payment_details_rule2_check` CHECK (`bank_charge` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_details');
    }
};
