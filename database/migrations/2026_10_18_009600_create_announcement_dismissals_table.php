<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `announcement_dismissals` (Platform): migration 96 of 97, dependency level 2.
 * Who closed which announcement (PEP-513), so it does not come back for them.
 * Depends on: announcements, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Who closed which announcement (PEP-513), so it does not come back for them.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['announcement_id', 'user_id'], 'announcement_dismissals_announcement_id_user_id_unique');
            $table->index(['user_id'], 'announcement_dismissals_user_id_index');

            $table->foreign('announcement_id', 'announcement_dismissals_announcement_id_foreign')->references('id')->on('announcements')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'announcement_dismissals_user_id_foreign')->references('id')->on('users')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
    }
};
