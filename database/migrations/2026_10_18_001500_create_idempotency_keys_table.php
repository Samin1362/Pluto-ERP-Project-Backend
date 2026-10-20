<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `idempotency_keys` (Platform): migration 15 of 83, dependency level 2.
 * Replay protection for POST/PUT that create or post documents (Idempotency-Key header).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Replay protection for POST/PUT that create or post documents (Idempotency-Key header).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->nullable()->comment('RESTRICT (feeds a generated column). NULL for requests without company context, e.g. POST /companies (R-21)');
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 80);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('expires_at');
            $table->unsignedBigInteger('scope_company_id')->storedAs('IFNULL(`company_id`, 0)')->nullable(false)->comment('Generated: 0 when there is no company');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['scope_company_id', 'user_id', 'idempotency_key'], 'idempotency_keys_scope_company_id_user_id_idempotency_key_unique');
            $table->index(['expires_at'], 'idempotency_keys_expires_at_index');
            $table->index(['company_id'], 'idempotency_keys_company_id_index');
            $table->index(['user_id'], 'idempotency_keys_user_id_index');

            $table->foreign('company_id', 'idempotency_keys_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'idempotency_keys_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
