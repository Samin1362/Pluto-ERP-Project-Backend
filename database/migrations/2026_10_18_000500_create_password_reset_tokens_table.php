<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `password_reset_tokens` (Access): migration 5 of 83, dependency level 1.
 * Single-use password reset tokens (60 minutes).
 * Depends on: users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Single-use password reset tokens (60 minutes).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['token_hash'], 'password_reset_tokens_token_hash_unique');
            $table->index(['user_id'], 'password_reset_tokens_user_id_index');

            $table->foreign('user_id', 'password_reset_tokens_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
