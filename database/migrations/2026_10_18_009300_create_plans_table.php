<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `plans` (Platform): migration 93 of 98, dependency level 1.
 * Pluto plans the software owner sells (PEP-498): a user limit, a branch limit (NULL = unlimited) and the modules included. Platform-wide, not a company's data; companies.plan_id points here.
 * Depends on: users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Pluto plans the software owner sells (PEP-498): a user limit, a branch limit (NULL = unlimited) and the modules included. Platform-wide, not a company\'s data; companies.plan_id points here.');

            $table->id()->comment('Primary key, auto-increment');
            $table->string('slug', 40)->comment('basic, business, import_pro, or one the owner adds');
            $table->string('name', 80);
            $table->unsignedSmallInteger('max_users')->comment('Users allowed; Pluto support memberships never count');
            $table->unsignedSmallInteger('max_branches')->nullable()->comment('Active branches allowed; NULL = unlimited');
            $table->json('modules')->comment('Module keys included, e.g. ["lc_management", "lc_finance"]');
            $table->boolean('is_active')->default(true)->comment('Inactive plans cannot be given to more companies');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['slug'], 'plans_slug_unique');
            $table->index(['created_by'], 'plans_created_by_index');
            $table->index(['updated_by'], 'plans_updated_by_index');

            $table->foreign('created_by', 'plans_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'plans_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
