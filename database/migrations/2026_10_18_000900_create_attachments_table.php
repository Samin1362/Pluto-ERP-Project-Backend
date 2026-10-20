<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `attachments` (Platform): migration 9 of 83, dependency level 2.
 * Uploaded file metadata (JPG/PNG/PDF up to 10 MB). Polymorphic owner; files live in private object storage.
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Uploaded file metadata (JPG/PNG/PDF up to 10 MB). Polymorphic owner; files live in private object storage.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->string('attachable_type', 60);
            $table->unsignedBigInteger('attachable_id');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_disk', 30);
            $table->string('storage_path', 255);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'attachable_type', 'attachable_id'], 'attachments_company_id_attachable_type_attachable_id_index');
            $table->index(['created_by'], 'attachments_created_by_index');
            $table->index(['updated_by'], 'attachments_updated_by_index');

            $table->foreign('company_id', 'attachments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'attachments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'attachments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `attachments`
                    ADD CONSTRAINT `attachments_rule1_check` CHECK (`size_bytes` <= 10485760)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
