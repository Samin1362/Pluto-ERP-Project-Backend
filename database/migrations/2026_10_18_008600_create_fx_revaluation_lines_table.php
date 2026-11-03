<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `fx_revaluation_lines` (LC): migration 86 of 90, dependency level 9.
 * One revalued item: an open foreign purchase bill or an open usance acceptance.
 * Depends on: currencies, fx_revaluations, letters_of_credit, parties, purchase_bills.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluation_lines', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('One revalued item: an open foreign purchase bill or an open usance acceptance.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('fx_revaluation_id');
            $table->string('source', 30)->comment('One of: purchase_bill, lc_acceptance');
            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->unsignedBigInteger('letter_of_credit_id')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('foreign_outstanding', 18, 2);
            $table->decimal('book_rate', 18, 6);
            $table->decimal('closing_rate', 18, 6);
            $table->decimal('difference', 18, 2)->comment('foreign_outstanding x (closing - book), signed: + loss, - gain');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['fx_revaluation_id'], 'fx_revaluation_lines_fx_revaluation_id_index');
            $table->index(['purchase_bill_id'], 'fx_revaluation_lines_purchase_bill_id_index');
            $table->index(['letter_of_credit_id'], 'fx_revaluation_lines_letter_of_credit_id_index');
            $table->index(['party_id'], 'fx_revaluation_lines_party_id_index');
            $table->index(['currency_code'], 'fx_revaluation_lines_currency_code_index');

            $table->foreign('fx_revaluation_id', 'fx_revaluation_lines_fx_revaluation_id_foreign')->references('id')->on('fx_revaluations')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('purchase_bill_id', 'fx_revaluation_lines_purchase_bill_id_foreign')->references('id')->on('purchase_bills')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'fx_revaluation_lines_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'fx_revaluation_lines_party_id_foreign')->references('id')->on('parties')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('currency_code', 'fx_revaluation_lines_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `fx_revaluation_lines`
                    ADD CONSTRAINT `fx_revaluation_lines_source_check` CHECK (`source` IN ('purchase_bill', 'lc_acceptance'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_lines');
    }
};
