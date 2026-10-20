<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_amendments` (LC): migration 65 of 83, dependency level 7.
 * Bank amendment to LC terms (requested > issued > accepted/refused).
 * Depends on: companies, letters_of_credit, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_amendments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Bank amendment to LC terms (requested > issued > accepted/refused).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedSmallInteger('amendment_no');
            $table->date('requested_on');
            $table->date('issued_on')->nullable();
            $table->date('accepted_on')->nullable();
            $table->string('status', 30)->default('requested')->comment('One of: requested, issued, accepted, refused');
            $table->string('reason', 500)->nullable();
            $table->string('other_change', 255)->nullable();
            $table->decimal('bank_fee', 18, 2)->default(0);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['letter_of_credit_id', 'amendment_no'], 'lc_amendments_letter_of_credit_id_amendment_no_unique');
            $table->index(['company_id'], 'lc_amendments_company_id_index');
            $table->index(['created_by'], 'lc_amendments_created_by_index');
            $table->index(['updated_by'], 'lc_amendments_updated_by_index');

            $table->foreign('company_id', 'lc_amendments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'lc_amendments_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_amendments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_amendments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_amendments`
                    ADD CONSTRAINT `lc_amendments_status_check` CHECK (`status` IN ('requested', 'issued', 'accepted', 'refused'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_amendments');
    }
};
