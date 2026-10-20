<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `permissions` (Access): migration 2 of 83, dependency level 0.
 * Global catalogue of permission keys, e.g. sales.create, accounting.approve, inventory.view_costs.
 * Depends on: nothing.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Global catalogue of permission keys, e.g. sales.create, accounting.approve, inventory.view_costs.');

            $table->id()->comment('Primary key, auto-increment');
            $table->string('key', 80);
            $table->string('module', 40);
            $table->string('action', 30);
            $table->string('description', 255);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['key'], 'permissions_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
