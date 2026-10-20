<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_documents` (LC): migration 72 of 83, dependency level 8.
 * Required document of one LC and its status (scans in attachments).
 * Depends on: companies, lc_shipments, letters_of_credit, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_documents', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Required document of one LC and its status (scans in attachments).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedBigInteger('lc_shipment_id')->nullable();
            $table->string('name', 120);
            $table->string('provided_by', 80);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('note', 255)->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('status', 30)->default('not_yet_due')->comment('One of: not_yet_due, pending, received, missing, discrepant, not_required');
            $table->date('due_date')->nullable();
            $table->date('received_date')->nullable();
            $table->string('discrepancy_note', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id'], 'lc_documents_company_id_index');
            $table->index(['letter_of_credit_id', 'status'], 'lc_documents_letter_of_credit_id_status_index');
            $table->index(['lc_shipment_id'], 'lc_documents_lc_shipment_id_index');
            $table->index(['created_by'], 'lc_documents_created_by_index');
            $table->index(['updated_by'], 'lc_documents_updated_by_index');

            $table->foreign('company_id', 'lc_documents_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'lc_documents_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('lc_shipment_id', 'lc_documents_lc_shipment_id_foreign')->references('id')->on('lc_shipments')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_documents_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_documents_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_documents`
                    ADD CONSTRAINT `lc_documents_status_check` CHECK (`status` IN ('not_yet_due', 'pending', 'received', 'missing', 'discrepant', 'not_required'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_documents');
    }
};
