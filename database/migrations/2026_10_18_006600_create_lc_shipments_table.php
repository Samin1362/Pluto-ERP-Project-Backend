<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_shipments` (LC): migration 66 of 83, dependency level 7.
 * Shipment lot of an LC (lives inside LC Details; not a separate module).
 * Depends on: companies, letters_of_credit, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_shipments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Shipment lot of an LC (lives inside LC Details; not a separate module).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedSmallInteger('lot_no');
            $table->string('status', 30)->default('not_shipped')->comment('One of: not_shipped, booking_confirmed, at_sea, arrived, arrived_cleared, not_shipped_expired');
            $table->string('booking_ref', 60)->nullable();
            $table->string('bl_number', 60)->nullable();
            $table->string('vessel', 120)->nullable();
            $table->string('port_of_loading', 120)->nullable();
            $table->string('port_of_discharge', 120)->nullable();
            $table->string('mode', 30)->default('sea')->comment('One of: sea, air, road');
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->date('shipped_on')->nullable();
            $table->date('arrived_on')->nullable();
            $table->decimal('quantity', 18, 3)->nullable();
            $table->decimal('value_foreign', 18, 2)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['letter_of_credit_id', 'lot_no'], 'lc_shipments_letter_of_credit_id_lot_no_unique');
            $table->index(['company_id'], 'lc_shipments_company_id_index');
            $table->index(['created_by'], 'lc_shipments_created_by_index');
            $table->index(['updated_by'], 'lc_shipments_updated_by_index');

            $table->foreign('company_id', 'lc_shipments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'lc_shipments_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_shipments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_shipments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_shipments`
                    ADD CONSTRAINT `lc_shipments_status_check` CHECK (`status` IN ('not_shipped', 'booking_confirmed', 'at_sea', 'arrived', 'arrived_cleared', 'not_shipped_expired')),
                    ADD CONSTRAINT `lc_shipments_mode_check` CHECK (`mode` IN ('sea', 'air', 'road'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_shipments');
    }
};
