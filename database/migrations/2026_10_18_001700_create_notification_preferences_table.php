<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `notification_preferences` (Platform): migration 17 of 83, dependency level 2.
 * Per user, company and event: in-app, e-mail and SMS switches (K12).
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Per user, company and event: in-app, e-mail and SMS switches (K12).');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('user_id');
            $table->string('event_key', 60);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->boolean('sms')->default(false);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'user_id', 'event_key'], 'notification_preferences_company_id_user_id_event_key_unique');
            $table->index(['user_id'], 'notification_preferences_user_id_index');

            $table->foreign('company_id', 'notification_preferences_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'notification_preferences_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
