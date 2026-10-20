<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_amendment_changes` (LC): migration 71 of 83, dependency level 8.
 * Field-level before/after of an amendment. Accepted changes update the LC.
 * Depends on: lc_amendments.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_amendment_changes', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Field-level before/after of an amendment. Accepted changes update the LC.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('lc_amendment_id');
            $table->string('field_key', 30)->comment('One of: expiry_date, latest_shipment_date, amount, tolerance_pct, other');
            $table->string('old_value', 120)->nullable();
            $table->string('new_value', 120);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['lc_amendment_id'], 'lc_amendment_changes_lc_amendment_id_index');

            $table->foreign('lc_amendment_id', 'lc_amendment_changes_lc_amendment_id_foreign')->references('id')->on('lc_amendments')->cascadeOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_amendment_changes`
                    ADD CONSTRAINT `lc_amendment_changes_field_key_check` CHECK (`field_key` IN ('expiry_date', 'latest_shipment_date', 'amount', 'tolerance_pct', 'other'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_amendment_changes');
    }
};
