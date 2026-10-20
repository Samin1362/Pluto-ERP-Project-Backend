<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `approval_requests` (Accounting): migration 8 of 83, dependency level 2.
 * A document waiting for approval because it is above the role limit (L4 drawer).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A document waiting for approval because it is above the role limit (L4 drawer).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->decimal('amount', 18, 2);
            $table->string('required_permission', 80);
            $table->string('status', 30)->default('pending')->comment('One of: pending, approved, rejected, cancelled');
            $table->unsignedBigInteger('requested_by');
            $table->dateTime('requested_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('comment', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'status'], 'approval_requests_company_id_status_index');
            $table->index(['company_id', 'document_type', 'document_id'], 'approval_requests_company_id_document_type_document_id_index');
            $table->index(['requested_by'], 'approval_requests_requested_by_index');
            $table->index(['decided_by'], 'approval_requests_decided_by_index');
            $table->index(['created_by'], 'approval_requests_created_by_index');
            $table->index(['updated_by'], 'approval_requests_updated_by_index');

            $table->foreign('company_id', 'approval_requests_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('requested_by', 'approval_requests_requested_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('decided_by', 'approval_requests_decided_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'approval_requests_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'approval_requests_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `approval_requests`
                    ADD CONSTRAINT `approval_requests_status_check` CHECK (`status` IN ('pending', 'approved', 'rejected', 'cancelled'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
