<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `cash_count_denominations` (Money): migration 54 of 83, dependency level 6.
 * Note/coin lines of a cash count.
 * Depends on: cash_counts.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_count_denominations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Note/coin lines of a cash count.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('cash_count_id');
            $table->decimal('denomination', 18, 2);
            $table->unsignedInteger('count');
            $table->decimal('value', 18, 2);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['cash_count_id', 'denomination'], 'cash_count_denominations_cash_count_id_denomination_unique');

            $table->foreign('cash_count_id', 'cash_count_denominations_cash_count_id_foreign')->references('id')->on('cash_counts')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_denominations');
    }
};
