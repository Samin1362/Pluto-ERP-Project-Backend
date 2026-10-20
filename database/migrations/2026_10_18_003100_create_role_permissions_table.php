<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `role_permissions` (Access): migration 31 of 83, dependency level 3.
 * Junction: which permissions a role grants (many-to-many roles to permissions).
 * Depends on: permissions, roles.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Junction: which permissions a role grants (many-to-many roles to permissions).');

            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');

            $table->primary(['role_id', 'permission_id']);

            $table->index(['permission_id'], 'role_permissions_permission_id_index');

            $table->foreign('role_id', 'role_permissions_role_id_foreign')->references('id')->on('roles')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('permission_id', 'role_permissions_permission_id_foreign')->references('id')->on('permissions')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
