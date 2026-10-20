<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `company_user_branches` (Access): migration 34 of 83, dependency level 4.
 * Optional branch restriction (many-to-many memberships to branches). No rows = all branches.
 * Depends on: branches, company_users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user_branches', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Optional branch restriction (many-to-many memberships to branches). No rows = all branches.');

            $table->unsignedBigInteger('company_user_id');
            $table->unsignedBigInteger('branch_id')->comment('RESTRICT: deleting a branch must not silently widen a restricted user to all branches');

            $table->primary(['company_user_id', 'branch_id']);

            $table->index(['branch_id'], 'company_user_branches_branch_id_index');

            $table->foreign('company_user_id', 'company_user_branches_company_user_id_foreign')->references('id')->on('company_users')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'company_user_branches_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user_branches');
    }
};
