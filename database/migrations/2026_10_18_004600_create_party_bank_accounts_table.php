<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `party_bank_accounts` (Parties): migration 46 of 83, dependency level 5.
 * Bank details of a supplier (pay-to) or advising bank of an international supplier.
 * Depends on: companies, parties, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_bank_accounts', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Bank details of a supplier (pay-to) or advising bank of an international supplier.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('party_id');
            $table->string('bank_name', 120);
            $table->string('branch', 120)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 40);
            $table->string('routing_number', 20)->nullable();
            $table->string('swift_code', 11)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id'], 'party_bank_accounts_company_id_index');
            $table->index(['party_id'], 'party_bank_accounts_party_id_index');
            $table->index(['created_by'], 'party_bank_accounts_created_by_index');
            $table->index(['updated_by'], 'party_bank_accounts_updated_by_index');

            $table->foreign('company_id', 'party_bank_accounts_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'party_bank_accounts_party_id_foreign')->references('id')->on('parties')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'party_bank_accounts_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'party_bank_accounts_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_bank_accounts');
    }
};
