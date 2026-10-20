<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `letters_of_credit` (LC): migration 55 of 83, dependency level 6.
 * Import letter of credit (M3/M4). Shipment lots, documents, amendments, charges and payments hang off it.
 * Depends on: branches, companies, currencies, money_accounts, parties, purchase_orders, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letters_of_credit', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Import letter of credit (M3/M4). Shipment lots, documents, amendments, charges and payments hang off it.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('lc_number', 30)->comment('Internal LC-2026-022');
            $table->unsignedBigInteger('party_id')->comment('Beneficiary (supplier)');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('intended_import', 255)->nullable()->comment('When not yet linked to a PO');
            $table->string('goods_description', 500);
            $table->decimal('quantity', 18, 3)->nullable();
            $table->string('quantity_unit', 20)->nullable();
            $table->string('import_authorization_ref', 60)->nullable();
            $table->string('lc_type', 30)->default('irrevocable')->comment('One of: irrevocable, irrevocable_transferable, standby');
            $table->string('tenor', 30)->default('sight')->comment('One of: sight, usance');
            $table->unsignedSmallInteger('usance_days')->nullable();
            $table->string('incoterm', 40);
            $table->char('currency_code', 3);
            $table->decimal('amount', 18, 2)->comment('LC amount in LC currency');
            $table->decimal('tolerance_pct', 7, 4)->default(0);
            $table->decimal('planning_rate', 18, 6)->comment('BDT per unit of LC currency, estimate only');
            $table->boolean('partial_shipments')->default(false);
            $table->boolean('transhipment')->default(false);
            $table->boolean('confirmation_required')->default(false);
            $table->unsignedBigInteger('issuing_bank_account_id')->nullable()->comment('Our bank that issues the LC; required once submitted (BR-039)');
            $table->string('advising_bank', 190)->nullable();
            $table->decimal('margin_pct', 7, 4)->default(0);
            $table->unsignedBigInteger('margin_account_id')->nullable();
            $table->date('application_date')->nullable();
            $table->date('latest_shipment_date')->nullable();
            $table->date('expiry_date')->nullable()->comment('Required once submitted (BR-039)');
            $table->unsignedSmallInteger('presentation_days')->default(21);
            $table->date('maturity_date')->nullable()->comment('Usance LCs');
            $table->string('opening_status', 30)->default('not_applied')->comment('One of: not_applied, submitted, opened, refused');
            $table->string('bank_reference', 60)->nullable()->comment('Required once opened');
            $table->date('opened_on')->nullable();
            $table->string('status', 30)->default('draft')->comment('One of: draft, awaiting_bank, opened, partly_shipped, documents_presented, payment_due, closed, cancelled, expired');
            $table->boolean('record_shipments')->default(true);
            $table->string('bank_action_note', 255)->nullable();
            $table->date('closed_on')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'lc_number'], 'letters_of_credit_company_id_lc_number_unique');
            $table->index(['company_id', 'status', 'expiry_date'], 'letters_of_credit_company_id_status_expiry_date_index');
            $table->index(['branch_id'], 'letters_of_credit_branch_id_index');
            $table->index(['party_id'], 'letters_of_credit_party_id_index');
            $table->index(['purchase_order_id'], 'letters_of_credit_purchase_order_id_index');
            $table->index(['currency_code'], 'letters_of_credit_currency_code_index');
            $table->index(['issuing_bank_account_id'], 'letters_of_credit_issuing_bank_account_id_index');
            $table->index(['margin_account_id'], 'letters_of_credit_margin_account_id_index');
            $table->index(['closed_by'], 'letters_of_credit_closed_by_index');
            $table->index(['created_by'], 'letters_of_credit_created_by_index');
            $table->index(['updated_by'], 'letters_of_credit_updated_by_index');

            $table->foreign('company_id', 'letters_of_credit_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'letters_of_credit_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'letters_of_credit_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_order_id', 'letters_of_credit_purchase_order_id_foreign')->references('id')->on('purchase_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('currency_code', 'letters_of_credit_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('issuing_bank_account_id', 'letters_of_credit_issuing_bank_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('margin_account_id', 'letters_of_credit_margin_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('closed_by', 'letters_of_credit_closed_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'letters_of_credit_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'letters_of_credit_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `letters_of_credit`
                    ADD CONSTRAINT `letters_of_credit_lc_type_check` CHECK (`lc_type` IN ('irrevocable', 'irrevocable_transferable', 'standby')),
                    ADD CONSTRAINT `letters_of_credit_tenor_check` CHECK (`tenor` IN ('sight', 'usance')),
                    ADD CONSTRAINT `letters_of_credit_opening_status_check` CHECK (`opening_status` IN ('not_applied', 'submitted', 'opened', 'refused')),
                    ADD CONSTRAINT `letters_of_credit_status_check` CHECK (`status` IN ('draft', 'awaiting_bank', 'opened', 'partly_shipped', 'documents_presented', 'payment_due', 'closed', 'cancelled', 'expired')),
                    ADD CONSTRAINT `letters_of_credit_rule1_check` CHECK (`amount` > 0),
                    ADD CONSTRAINT `letters_of_credit_rule2_check` CHECK (`tolerance_pct` >= 0),
                    ADD CONSTRAINT `letters_of_credit_rule3_check` CHECK (`tenor` = 'sight' OR `usance_days` IS NOT NULL),
                    ADD CONSTRAINT `letters_of_credit_rule4_check` CHECK (`status` = 'draft' OR (`issuing_bank_account_id` IS NOT NULL AND `expiry_date` IS NOT NULL))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('letters_of_credit');
    }
};
