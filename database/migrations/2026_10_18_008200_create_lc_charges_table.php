<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_charges` (LC): migration 82 of 83, dependency level 10.
 * Bank charge or import expense of an LC (opening commission, insurance, C&F, port, duties as assessed, margin deposit, ...).
 * Depends on: companies, journal_entries, landed_cost_allocations, lc_amendments, lc_shipments, letters_of_credit, money_accounts, parties, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_charges', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Bank charge or import expense of an LC (opening commission, insurance, C&F, port, duties as assessed, margin deposit, ...).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedBigInteger('lc_amendment_id')->nullable();
            $table->unsignedBigInteger('lc_shipment_id')->nullable();
            $table->string('charge_type', 30)->comment('One of: margin_deposit, opening_commission, amendment_fee, swift_postage, stamp_documentation, acceptance_commission, discrepancy_fee, marine_insurance, cf_agent_fee, port_handling, customs_duty_taxes, inland_transport, other');
            $table->date('charge_date');
            $table->string('paid_to', 150)->nullable();
            $table->unsignedBigInteger('payee_party_id')->nullable()->comment('When payable to a supplier ledger (C&F agent)');
            $table->decimal('amount', 18, 2)->comment('BDT');
            $table->string('status', 30)->default('unpaid')->comment('One of: unpaid, paid');
            $table->string('posting_status', 30)->default('posted')->comment('One of: draft, posted, reversed. Reversed through POST /lc-charges/{id}/reverse (R-03)');
            $table->unsignedBigInteger('paid_from_account_id')->nullable();
            $table->boolean('include_in_landed_cost')->default(true);
            $table->boolean('is_refundable')->default(false)->comment('Margin deposit: asset, never landed cost');
            $table->string('reference', 60)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('landed_cost_allocation_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['journal_entry_id'], 'lc_charges_journal_entry_id_unique');
            $table->index(['company_id'], 'lc_charges_company_id_index');
            $table->index(['letter_of_credit_id', 'status'], 'lc_charges_letter_of_credit_id_status_index');
            $table->index(['lc_amendment_id'], 'lc_charges_lc_amendment_id_index');
            $table->index(['lc_shipment_id'], 'lc_charges_lc_shipment_id_index');
            $table->index(['payee_party_id'], 'lc_charges_payee_party_id_index');
            $table->index(['paid_from_account_id'], 'lc_charges_paid_from_account_id_index');
            $table->index(['landed_cost_allocation_id'], 'lc_charges_landed_cost_allocation_id_index');
            $table->index(['created_by'], 'lc_charges_created_by_index');
            $table->index(['updated_by'], 'lc_charges_updated_by_index');

            $table->foreign('company_id', 'lc_charges_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'lc_charges_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_amendment_id', 'lc_charges_lc_amendment_id_foreign')->references('id')->on('lc_amendments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_shipment_id', 'lc_charges_lc_shipment_id_foreign')->references('id')->on('lc_shipments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('payee_party_id', 'lc_charges_payee_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('paid_from_account_id', 'lc_charges_paid_from_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'lc_charges_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('landed_cost_allocation_id', 'lc_charges_landed_cost_allocation_id_foreign')->references('id')->on('landed_cost_allocations')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_charges_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_charges_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_charges`
                    ADD CONSTRAINT `lc_charges_charge_type_check` CHECK (`charge_type` IN ('margin_deposit', 'opening_commission', 'amendment_fee', 'swift_postage', 'stamp_documentation', 'acceptance_commission', 'discrepancy_fee', 'marine_insurance', 'cf_agent_fee', 'port_handling', 'customs_duty_taxes', 'inland_transport', 'other')),
                    ADD CONSTRAINT `lc_charges_status_check` CHECK (`status` IN ('unpaid', 'paid')),
                    ADD CONSTRAINT `lc_charges_posting_status_check` CHECK (`posting_status` IN ('draft', 'posted', 'reversed')),
                    ADD CONSTRAINT `lc_charges_rule1_check` CHECK (`amount` > 0),
                    ADD CONSTRAINT `lc_charges_rule2_check` CHECK (NOT (`is_refundable` = 1 AND `include_in_landed_cost` = 1))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_charges');
    }
};
