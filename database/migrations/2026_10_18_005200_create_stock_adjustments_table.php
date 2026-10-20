<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_adjustments` (Inventory): migration 52 of 83, dependency level 5.
 * Count correction, damage, expiry, theft or samples (D10). Posts value difference.
 * Depends on: companies, journal_entries, users, warehouses.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Count correction, damage, expiry, theft or samples (D10). Posts value difference.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('number', 30);
            $table->date('adjustment_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('reason', 30)->comment('One of: count_difference, damaged, expired, theft_or_loss, free_samples');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft')->comment('One of: draft, posted, reversed');
            $table->decimal('net_value', 18, 2)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'number'], 'stock_adjustments_company_id_number_unique');
            $table->unique(['journal_entry_id'], 'stock_adjustments_journal_entry_id_unique');
            $table->index(['warehouse_id'], 'stock_adjustments_warehouse_id_index');
            $table->index(['posted_by'], 'stock_adjustments_posted_by_index');
            $table->index(['created_by'], 'stock_adjustments_created_by_index');
            $table->index(['updated_by'], 'stock_adjustments_updated_by_index');

            $table->foreign('company_id', 'stock_adjustments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('warehouse_id', 'stock_adjustments_warehouse_id_foreign')->references('id')->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('journal_entry_id', 'stock_adjustments_journal_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('posted_by', 'stock_adjustments_posted_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'stock_adjustments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'stock_adjustments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `stock_adjustments`
                    ADD CONSTRAINT `stock_adjustments_reason_check` CHECK (`reason` IN ('count_difference', 'damaged', 'expired', 'theft_or_loss', 'free_samples')),
                    ADD CONSTRAINT `stock_adjustments_status_check` CHECK (`status` IN ('draft', 'posted', 'reversed'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
