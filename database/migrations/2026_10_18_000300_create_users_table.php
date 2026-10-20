<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `users` (Platform): migration 3 of 83, dependency level 0.
 * A person who can sign in. Global; membership of companies is in company_users.
 * Depends on: nothing.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A person who can sign in. Global; membership of companies is in company_users.');

            $table->id()->comment('Primary key, auto-increment');
            $table->string('name', 120);
            $table->string('email', 190)->comment('Login identifier, stored lower-case');
            $table->string('phone', 20)->nullable();
            $table->string('password_hash', 255)->comment('Argon2id hash; never returned by the API');
            $table->string('status', 30)->default('invited')->comment('One of: active, invited, disabled');
            $table->dateTime('email_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable()->comment('TOTP secret, encrypted with APP_KEY (Laravel encrypted cast)');
            $table->text('two_factor_recovery_codes')->nullable()->comment('Encrypted JSON array of hashed recovery codes');
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->dateTime('locked_until')->nullable()->comment('Brute-force lockout end');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['email'], 'users_email_unique');
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `users`
                    ADD CONSTRAINT `users_status_check` CHECK (`status` IN ('active', 'invited', 'disabled'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
