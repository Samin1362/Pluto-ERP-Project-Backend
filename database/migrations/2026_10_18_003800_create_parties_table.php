<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `parties` (Parties): migration 38 of 83, dependency level 4.
 * Customer, prospect or supplier (local or international). Each has its own control-group ledger.
 * Depends on: companies, currencies, ledgers, party_groups, payment_terms, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Customer, prospect or supplier (local or international). Each has its own control-group ledger.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('party_type', 30)->comment('One of: customer, supplier');
            $table->string('code', 20)->comment('CUS-0012 / SUP-0004, generated');
            $table->string('name', 190);
            $table->string('category', 30)->nullable()->comment('One of: business, individual, walk_in, manufacturer, distributor, service_provider');
            $table->unsignedBigInteger('party_group_id')->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('mobile', 20)->nullable()->comment('Required for local parties: 01XXXXXXXXX');
            $table->string('email', 190)->nullable();
            $table->string('alt_phone', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('district', 60)->nullable();
            $table->string('area', 80)->nullable();
            $table->char('country_code', 2)->default('BD');
            $table->char('default_currency_code', 3)->comment('BDT for local parties');
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable()->comment('Customers only; warn before exceeding');
            $table->string('price_tier', 30)->default('standard')->comment('One of: standard, wholesale');
            $table->unsignedBigInteger('default_purchase_ledger_id')->nullable()->comment('Suppliers: ledger for non-stock lines');
            $table->unsignedBigInteger('ledger_id')->comment('The party\'s own ledger (1:1)');
            $table->string('tax_registration_no', 40)->nullable();
            $table->string('port_of_loading', 120)->nullable()->comment('International suppliers');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('active')->comment('One of: active, inactive, prospect');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('deleted_at')->nullable()->comment('Soft delete (master data only, never financial rows)');

            $table->unique(['company_id', 'code'], 'parties_company_id_code_unique');
            $table->unique(['ledger_id'], 'parties_ledger_id_unique');
            $table->index(['company_id', 'party_type', 'status'], 'parties_company_id_party_type_status_index');
            $table->index(['company_id', 'name'], 'parties_company_id_name_index');
            $table->index(['company_id', 'mobile'], 'parties_company_id_mobile_index');
            $table->index(['party_group_id'], 'parties_party_group_id_index');
            $table->index(['default_currency_code'], 'parties_default_currency_code_index');
            $table->index(['payment_term_id'], 'parties_payment_term_id_index');
            $table->index(['default_purchase_ledger_id'], 'parties_default_purchase_ledger_id_index');
            $table->index(['created_by'], 'parties_created_by_index');
            $table->index(['updated_by'], 'parties_updated_by_index');

            $table->foreign('company_id', 'parties_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_group_id', 'parties_party_group_id_foreign')->references('id')->on('party_groups')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('default_currency_code', 'parties_default_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('payment_term_id', 'parties_payment_term_id_foreign')->references('id')->on('payment_terms')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('default_purchase_ledger_id', 'parties_default_purchase_ledger_id_foreign')->references('id')->on('ledgers')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('ledger_id', 'parties_ledger_id_foreign')->references('id')->on('ledgers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'parties_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'parties_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `parties`
                    ADD CONSTRAINT `parties_party_type_check` CHECK (`party_type` IN ('customer', 'supplier')),
                    ADD CONSTRAINT `parties_category_check` CHECK (`category` IN ('business', 'individual', 'walk_in', 'manufacturer', 'distributor', 'service_provider')),
                    ADD CONSTRAINT `parties_price_tier_check` CHECK (`price_tier` IN ('standard', 'wholesale')),
                    ADD CONSTRAINT `parties_status_check` CHECK (`status` IN ('active', 'inactive', 'prospect')),
                    ADD CONSTRAINT `parties_rule1_check` CHECK (`credit_limit` IS NULL OR `credit_limit` >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
