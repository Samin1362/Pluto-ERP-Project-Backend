<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `announcements` (Platform): migration 95 of 99, dependency level 1.
 * Messages the software owner shows to every company (PEP-513), e.g. 'Update tonight 11 pm'. Platform-wide, not a company's data.
 * Depends on: users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Messages the software owner shows to every company (PEP-513), e.g. \'Update tonight 11 pm\'. Platform-wide, not a company\'s data.');

            $table->id()->comment('Primary key, auto-increment');
            $table->string('title', 120);
            $table->string('body', 500)->nullable();
            $table->string('tone', 30)->default('info')->comment('One of: info, warning, success');
            $table->dateTime('starts_at')->nullable()->comment('Shown from this moment; NULL = at once');
            $table->dateTime('ends_at')->nullable()->comment('Hidden after this moment; NULL = until stopped');
            $table->boolean('is_active')->default(true)->comment('0 = stopped by the software owner');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['is_active', 'starts_at'], 'announcements_is_active_starts_at_index');
            $table->index(['created_by'], 'announcements_created_by_index');
            $table->index(['updated_by'], 'announcements_updated_by_index');

            $table->foreign('created_by', 'announcements_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'announcements_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `announcements`
                    ADD CONSTRAINT `announcements_tone_check` CHECK (`tone` IN ('info', 'warning', 'success'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
