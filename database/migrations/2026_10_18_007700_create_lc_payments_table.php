<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_payments` (LC): migration 77 of 83, dependency level 9.
 * Payment to the beneficiary through the bank, at the bank's rate (sight or usance maturity).
 * Depends on: companies, journal_entries, lc_shipments, letters_of_credit, money_accounts, purchase_bills, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_payments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Payment to the beneficiary through the bank, at the bank\'s rate (sight or usance maturity).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedBigInteger('lc_shipment_id')->nullable();
            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->string('payment_kind', 30)->default('sight_payment')->comment('One of: sight_payment, usance_acceptance, usance_settlement. Usance: acceptance books the bank liability, settlement pays it at maturity');
            $table->date('payment_date');
            $table->string('description', 150)->nullable();
            $table->decimal('amount_foreign', 18, 2);
            $table->decimal('bank_rate', 18, 6);
            $table->decimal('amount_bdt', 18, 2)->comment('amount_foreign x bank_rate, rounded');
            $table->unsignedBigInteger('money_account_id')->comment('Debited from');
            $table->decimal('margin_applied', 18, 2)->default(0);
            $table->string('bank_reference', 60)->nullable();
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, reversed');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'lc_payments_journal_entry_id_unique');
            $table->index(['company_id'], 'lc_payments_company_id_index');
            $table->index(['letter_of_credit_id'], 'lc_payments_letter_of_credit_id_index');
            $table->index(['lc_shipment_id'], 'lc_payments_lc_shipment_id_index');
            $table->index(['purchase_bill_id'], 'lc_payments_purchase_bill_id_index');
            $table->index(['money_account_id'], 'lc_payments_money_account_id_index');
            $table->index(['created_by'], 'lc_payments_created_by_index');
            $table->index(['updated_by'], 'lc_payments_updated_by_index');

            $table->foreign('company_id', 'lc_payments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'lc_payments_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_shipment_id', 'lc_payments_lc_shipment_id_foreign')->references('id')->on('lc_shipments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_id', 'lc_payments_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'lc_payments_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'lc_payments_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_payments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_payments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_payments`
                    ADD CONSTRAINT `lc_payments_payment_kind_check` CHECK (`payment_kind` IN ('sight_payment', 'usance_acceptance', 'usance_settlement')),
                    ADD CONSTRAINT `lc_payments_status_check` CHECK (`status` IN ('draft', 'posted', 'reversed')),
                    ADD CONSTRAINT `lc_payments_rule1_check` CHECK (`amount_foreign` > 0),
                    ADD CONSTRAINT `lc_payments_rule2_check` CHECK (`bank_rate` > 0),
                    ADD CONSTRAINT `lc_payments_rule3_check` CHECK (`margin_applied` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_payments');
    }
};
