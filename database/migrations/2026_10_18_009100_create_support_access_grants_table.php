<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `support_access_grants` (Access): migration 91 of 92, dependency level 2.
 * Read-only support access (PEP-485): a platform admin opened this company for a limited time with a reason; the company's owners are told and the start and end are in its audit log. While it lasts the admin may only read (every change is refused with support_read_only).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Read-only support access (PEP-485): a platform admin opened this company for a limited time with a reason; the company\'s owners are told and the start and end are in its audit log. While it lasts the admin may only read (every change is refused with support_read_only).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('user_id')->comment('The platform admin');
            $table->string('reason', 255);
            $table->dateTime('expires_at');
            $table->dateTime('ended_at')->nullable()->comment('Ended early by the admin');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'user_id', 'expires_at'], 'support_access_grants_company_id_user_id_expires_at_index');
            $table->index(['user_id'], 'support_access_grants_user_id_index');
            $table->index(['created_by'], 'support_access_grants_created_by_index');
            $table->index(['updated_by'], 'support_access_grants_updated_by_index');

            $table->foreign('company_id', 'support_access_grants_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'support_access_grants_user_id_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'support_access_grants_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'support_access_grants_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
    }
};
