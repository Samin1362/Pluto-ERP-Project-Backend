<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `currencies` (Platform): migration 1 of 83, dependency level 0.
 * ISO 4217 reference list (BDT base, USD/EUR/CNY for imports). Global reference data.
 * Depends on: nothing.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('ISO 4217 reference list (BDT base, USD/EUR/CNY for imports). Global reference data.');

            $table->char('code', 3)->primary()->comment('ISO 4217 code, primary key');
            $table->string('name', 60);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('minor_units')->default(2)->comment('Decimal places');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
