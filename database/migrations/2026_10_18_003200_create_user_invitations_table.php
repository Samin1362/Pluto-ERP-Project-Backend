<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `user_invitations` (Access): migration 32 of 83, dependency level 3.
 * Pending invitation e-mail (link valid 7 days).
 * Depends on: companies, roles, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Pending invitation e-mail (link valid 7 days).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->unsignedBigInteger('role_id');
            $table->char('token_hash', 64)->comment('SHA-256 of the emailed token');
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['token_hash'], 'user_invitations_token_hash_unique');
            $table->index(['company_id', 'email'], 'user_invitations_company_id_email_index');
            $table->index(['role_id'], 'user_invitations_role_id_index');
            $table->index(['created_by'], 'user_invitations_created_by_index');
            $table->index(['updated_by'], 'user_invitations_updated_by_index');

            $table->foreign('company_id', 'user_invitations_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('role_id', 'user_invitations_role_id_foreign')->references('id')->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'user_invitations_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'user_invitations_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
