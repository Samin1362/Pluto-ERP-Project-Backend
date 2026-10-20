<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `quotations` (Sales): migration 50 of 83, dependency level 5.
 * Offer to a customer or prospect (E10). Never posts.
 * Depends on: branches, companies, parties, payment_terms, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Offer to a customer or prospect (E10). Never posts.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('branch_id');
            $table->string('number', 30)->comment('QT number, reserved at first save, never reused');
            $table->unsignedBigInteger('party_id')->comment('Customer or prospect');
            $table->json('party_snapshot')->nullable()->comment('Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)');
            $table->date('quotation_date');
            $table->date('valid_until');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_note')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0)->comment('Sum of quantity x price before discount');
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('rounding_adjustment', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0)->comment('Base currency (BDT)');
            $table->string('status', 30)->default('draft')->comment('One of: draft, sent, accepted, declined, expired, converted');
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'quotations_company_id_number_unique');
            $table->index(['company_id', 'status', 'valid_until'], 'quotations_company_id_status_valid_until_index');
            $table->index(['branch_id'], 'quotations_branch_id_index');
            $table->index(['party_id'], 'quotations_party_id_index');
            $table->index(['warehouse_id'], 'quotations_warehouse_id_index');
            $table->index(['payment_term_id'], 'quotations_payment_term_id_index');
            $table->index(['created_by'], 'quotations_created_by_index');
            $table->index(['updated_by'], 'quotations_updated_by_index');

            $table->foreign('company_id', 'quotations_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'quotations_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'quotations_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'quotations_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('payment_term_id', 'quotations_payment_term_id_foreign')->references('id')->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'quotations_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'quotations_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `quotations`
                    ADD CONSTRAINT `quotations_status_check` CHECK (`status` IN ('draft', 'sent', 'accepted', 'declined', 'expired', 'converted'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
