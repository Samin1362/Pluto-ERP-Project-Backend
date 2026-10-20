<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `user_invitation_branches` (Access): migration 40 of 83, dependency level 4.
 * Branch restriction chosen on the invitation (R-18); copied to company_user_branches on accept.
 * Depends on: branches, user_invitations.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitation_branches', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Branch restriction chosen on the invitation (R-18); copied to company_user_branches on accept.');

            $table->unsignedBigInteger('user_invitation_id');
            $table->unsignedBigInteger('branch_id');

            $table->primary(['user_invitation_id', 'branch_id']);

            $table->index(['branch_id'], 'user_invitation_branches_branch_id_index');

            $table->foreign('user_invitation_id', 'user_invitation_branches_user_invitation_id_foreign')->references('id')->on('user_invitations')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('branch_id', 'user_invitation_branches_branch_id_foreign')->references('id')->on('branches')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitation_branches');
    }
};
