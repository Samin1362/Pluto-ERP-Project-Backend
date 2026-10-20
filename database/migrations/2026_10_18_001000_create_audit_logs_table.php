<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `audit_logs` (Platform): migration 10 of 83, dependency level 2.
 * Append-only audit trail of every create/update/post/approve/reverse/cancel/delete and security event.
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Append-only audit trail of every create/update/post/approve/reverse/cancel/delete and security event.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->nullable()->comment('NULL for global security events');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 40)->comment('created, updated, posted, approved, rejected, reversed, cancelled, deleted, voided_number, login, login_failed, ...');
            $table->string('auditable_type', 60);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'auditable_type', 'auditable_id'], 'audit_logs_company_id_auditable_type_auditable_id_index');
            $table->index(['company_id', 'created_at'], 'audit_logs_company_id_created_at_index');
            $table->index(['user_id'], 'audit_logs_user_id_index');

            $table->foreign('company_id', 'audit_logs_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'audit_logs_user_id_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
