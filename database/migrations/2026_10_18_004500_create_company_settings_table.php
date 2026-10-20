<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `company_settings` (Platform): migration 45 of 83, dependency level 5.
 * One row per company: preferences, controls, currency display and invoice design (K3, K5, K6, K7, K14, K15).
 * Depends on: companies, payment_terms, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('One row per company: preferences, controls, currency display and invoice design (K3, K5, K6, K7, K14, K15).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('default_warehouse_id')->nullable();
            $table->unsignedBigInteger('default_payment_term_id')->nullable();
            $table->string('valuation_method', 30)->default('weighted_average')->comment('One of: weighted_average, fifo. FIFO reserved for a later phase');
            $table->string('invoice_rounding', 30)->default('nearest_1')->comment('One of: none, nearest_1, nearest_5');
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('require_approval')->default(true);
            $table->unsignedSmallInteger('backdate_warning_days')->default(7);
            $table->boolean('prices_include_tax')->default(false);
            $table->boolean('show_tax_no_on_invoice')->default(false);
            $table->string('symbol_position', 30)->default('before')->comment('One of: before, after');
            $table->unsignedTinyInteger('table_decimals')->default(2);
            $table->string('negative_format', 30)->default('parentheses')->comment('One of: parentheses, minus');
            $table->string('number_grouping', 30)->default('lakh')->comment('One of: lakh, million');
            $table->string('date_format', 20)->default('d M Y');
            $table->string('week_start', 30)->default('saturday')->comment('One of: saturday, sunday, monday');
            $table->string('print_language', 30)->default('en')->comment('One of: en, bn, both');
            $table->string('invoice_template', 30)->default('classic')->comment('One of: classic, banded, minimal');
            $table->char('invoice_accent_color', 7)->default('#0b6b5e');
            $table->boolean('invoice_show_logo')->default(true);
            $table->boolean('invoice_show_words')->default(true);
            $table->boolean('invoice_show_bank')->default(true);
            $table->boolean('invoice_show_signature')->default(true);
            $table->string('invoice_footer_note', 255)->nullable();
            $table->boolean('require_two_factor')->default(false);
            $table->unsignedSmallInteger('idle_timeout_minutes')->nullable()->default(60)->comment('NULL = never');
            $table->boolean('backup_enabled')->default(true);
            $table->unsignedSmallInteger('backup_retention_days')->default(90);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id'], 'company_settings_company_id_unique');
            $table->index(['default_warehouse_id'], 'company_settings_default_warehouse_id_index');
            $table->index(['default_payment_term_id'], 'company_settings_default_payment_term_id_index');
            $table->index(['created_by'], 'company_settings_created_by_index');
            $table->index(['updated_by'], 'company_settings_updated_by_index');

            $table->foreign('company_id', 'company_settings_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('default_warehouse_id', 'company_settings_default_warehouse_id_foreign')->references('id')->on('warehouses')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('default_payment_term_id', 'company_settings_default_payment_term_id_foreign')->references('id')->on('payment_terms')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'company_settings_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'company_settings_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `company_settings`
                    ADD CONSTRAINT `company_settings_valuation_method_check` CHECK (`valuation_method` IN ('weighted_average', 'fifo')),
                    ADD CONSTRAINT `company_settings_invoice_rounding_check` CHECK (`invoice_rounding` IN ('none', 'nearest_1', 'nearest_5')),
                    ADD CONSTRAINT `company_settings_symbol_position_check` CHECK (`symbol_position` IN ('before', 'after')),
                    ADD CONSTRAINT `company_settings_negative_format_check` CHECK (`negative_format` IN ('parentheses', 'minus')),
                    ADD CONSTRAINT `company_settings_number_grouping_check` CHECK (`number_grouping` IN ('lakh', 'million')),
                    ADD CONSTRAINT `company_settings_week_start_check` CHECK (`week_start` IN ('saturday', 'sunday', 'monday')),
                    ADD CONSTRAINT `company_settings_print_language_check` CHECK (`print_language` IN ('en', 'bn', 'both')),
                    ADD CONSTRAINT `company_settings_invoice_template_check` CHECK (`invoice_template` IN ('classic', 'banded', 'minimal'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
