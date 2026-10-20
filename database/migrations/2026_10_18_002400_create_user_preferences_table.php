<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `user_preferences` (Access): migration 24 of 83, dependency level 2.
 * Per-user appearance settings (K13) and last company used.
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Per-user appearance settings (K13) and last company used.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('user_id');
            $table->string('theme', 30)->default('system')->comment('One of: light, dark, system');
            $table->string('density', 30)->default('default')->comment('One of: default, compact');
            $table->string('text_size', 30)->default('m')->comment('One of: s, m, l');
            $table->boolean('reduce_motion')->default(false);
            $table->boolean('sidebar_collapsed')->default(false);
            $table->unsignedBigInteger('last_company_id')->nullable();
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['user_id'], 'user_preferences_user_id_unique');
            $table->index(['last_company_id'], 'user_preferences_last_company_id_index');

            $table->foreign('user_id', 'user_preferences_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('last_company_id', 'user_preferences_last_company_id_foreign')->references('id')->on('companies')->nullOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `user_preferences`
                    ADD CONSTRAINT `user_preferences_theme_check` CHECK (`theme` IN ('light', 'dark', 'system')),
                    ADD CONSTRAINT `user_preferences_density_check` CHECK (`density` IN ('default', 'compact')),
                    ADD CONSTRAINT `user_preferences_text_size_check` CHECK (`text_size` IN ('s', 'm', 'l'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
