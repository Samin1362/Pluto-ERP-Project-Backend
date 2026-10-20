<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `stock_groups` (Inventory): migration 22 of 83, dependency level 2.
 * Reporting hierarchy for stock valuation (D6). Does not choose the ledger (see GAP-20).
 * Depends on: companies, users. Self-reference: parent_id.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_groups', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Reporting hierarchy for stock valuation (D6). Does not choose the ledger (see GAP-20).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 80);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'name'], 'stock_groups_company_id_name_unique');
            $table->index(['parent_id'], 'stock_groups_parent_id_index');
            $table->index(['created_by'], 'stock_groups_created_by_index');
            $table->index(['updated_by'], 'stock_groups_updated_by_index');

            $table->foreign('company_id', 'stock_groups_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('parent_id', 'stock_groups_parent_id_foreign')->references('id')->on('stock_groups')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'stock_groups_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'stock_groups_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_groups');
    }
};
