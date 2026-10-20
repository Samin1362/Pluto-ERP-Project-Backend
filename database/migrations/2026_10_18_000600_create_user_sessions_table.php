<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `user_sessions` (Access): migration 6 of 83, dependency level 1.
 * Refresh-token session per device. Enables 'Where you're signed in' and 'Sign out everywhere'.
 * Depends on: users. Self-reference: replaced_by_id.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Refresh-token session per device. Enables \'Where you\'re signed in\' and \'Sign out everywhere\'.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('user_id');
            $table->char('refresh_token_hash', 64)->comment('SHA-256 of the opaque refresh token');
            $table->char('family_id', 36)->comment('UUID shared by a rotation chain; reuse of an old token revokes the family');
            $table->unsignedBigInteger('replaced_by_id')->nullable()->comment('Next token in the rotation chain');
            $table->string('device_label', 120)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 60)->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['refresh_token_hash'], 'user_sessions_refresh_token_hash_unique');
            $table->index(['user_id', 'revoked_at'], 'user_sessions_user_id_revoked_at_index');
            $table->index(['family_id'], 'user_sessions_family_id_index');
            $table->index(['replaced_by_id'], 'user_sessions_replaced_by_id_index');

            $table->foreign('user_id', 'user_sessions_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('replaced_by_id', 'user_sessions_replaced_by_id_foreign')->references('id')->on('user_sessions')->nullOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
