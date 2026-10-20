<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `party_groups` (Parties): migration 19 of 83, dependency level 2.
 * Customer or supplier group used for filtering and reports (Wholesale, Edible oils, ...).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_groups', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Customer or supplier group used for filtering and reports (Wholesale, Edible oils, ...).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('party_type', 30)->comment('One of: customer, supplier');
            $table->string('name', 80);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'party_type', 'name'], 'party_groups_company_id_party_type_name_unique');
            $table->index(['created_by'], 'party_groups_created_by_index');
            $table->index(['updated_by'], 'party_groups_updated_by_index');

            $table->foreign('company_id', 'party_groups_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'party_groups_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'party_groups_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `party_groups`
                    ADD CONSTRAINT `party_groups_party_type_check` CHECK (`party_type` IN ('customer', 'supplier'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('party_groups');
    }
};
