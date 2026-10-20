<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `cash_counts` (Money): migration 44 of 83, dependency level 5.
 * Physical cash count by denomination (G2); a difference posts to Cash short/over.
 * Depends on: companies, journal_entries, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Physical cash count by denomination (G2); a difference posts to Cash short/over.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('money_account_id');
            $table->dateTime('counted_at');
            $table->unsignedBigInteger('counted_by');
            $table->decimal('book_balance', 18, 2);
            $table->decimal('counted_total', 18, 2);
            $table->decimal('difference', 18, 2)->comment('counted - book');
            $table->string('note', 255)->nullable();
            $table->string('status', 30)->default('recorded')->comment('One of: recorded, adjusted, reversed');
            $table->unsignedBigInteger('adjustment_entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['adjustment_entry_id'], 'cash_counts_adjustment_entry_id_unique');
            $table->index(['company_id'], 'cash_counts_company_id_index');
            $table->index(['money_account_id'], 'cash_counts_money_account_id_index');
            $table->index(['counted_by'], 'cash_counts_counted_by_index');
            $table->index(['created_by'], 'cash_counts_created_by_index');
            $table->index(['updated_by'], 'cash_counts_updated_by_index');

            $table->foreign('company_id', 'cash_counts_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'cash_counts_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('counted_by', 'cash_counts_counted_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('adjustment_entry_id', 'cash_counts_adjustment_entry_id_foreign')->references('id')->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'cash_counts_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'cash_counts_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `cash_counts`
                    ADD CONSTRAINT `cash_counts_status_check` CHECK (`status` IN ('recorded', 'adjusted', 'reversed'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
    }
};
