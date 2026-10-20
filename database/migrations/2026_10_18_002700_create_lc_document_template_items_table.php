<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_document_template_items` (LC): migration 27 of 83, dependency level 3.
 * Document line of a template.
 * Depends on: lc_document_templates.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_document_template_items', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Document line of a template.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('lc_document_template_id');
            $table->string('name', 120);
            $table->string('provided_by', 80);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('note', 255)->nullable();
            $table->boolean('required_by_default')->default(true);
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['lc_document_template_id'], 'lc_document_template_items_lc_document_template_id_index');

            $table->foreign('lc_document_template_id', 'lc_document_template_items_lc_document_template_id_foreign')->references('id')->on('lc_document_templates')->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_document_template_items');
    }
};
