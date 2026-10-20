<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `notifications` (Platform): migration 18 of 83, dependency level 2.
 * In-app notification feed (L3) including approval requests.
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('In-app notification feed (L3) including approval requests.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('user_id');
            $table->string('event_key', 60);
            $table->string('title', 190);
            $table->string('body', 500)->nullable();
            $table->string('category', 30)->default('info')->comment('One of: approvals, alerts, info');
            $table->string('link_type', 60)->nullable();
            $table->unsignedBigInteger('link_id')->nullable();
            $table->json('data')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'user_id', 'read_at'], 'notifications_company_id_user_id_read_at_index');
            $table->index(['user_id'], 'notifications_user_id_index');

            $table->foreign('company_id', 'notifications_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'notifications_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `notifications`
                    ADD CONSTRAINT `notifications_category_check` CHECK (`category` IN ('approvals', 'alerts', 'info'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
