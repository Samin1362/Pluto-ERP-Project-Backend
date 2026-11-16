<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `payment_reminders` (Parties): migration 97 of 103, dependency level 5.
 * Payment reminders e-mailed to a customer about overdue invoices (PEP-516): by hand or by the daily job; at most one a week per customer.
 * Depends on: companies, parties, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Payment reminders e-mailed to a customer about overdue invoices (PEP-516): by hand or by the daily job; at most one a week per customer.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('party_id');
            $table->dateTime('sent_at');
            $table->string('trigger', 30)->comment('One of: manual, auto');
            $table->string('email', 190);
            $table->unsignedSmallInteger('invoices_count');
            $table->decimal('overdue_amount', 18, 2);
            $table->unsignedBigInteger('sent_by')->nullable()->comment('NULL = the daily job');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id'], 'payment_reminders_company_id_index');
            $table->index(['party_id'], 'payment_reminders_party_id_index');
            $table->index(['sent_by'], 'payment_reminders_sent_by_index');

            $table->foreign('company_id', 'payment_reminders_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('party_id', 'payment_reminders_party_id_foreign')->references('id')->on('parties')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('sent_by', 'payment_reminders_sent_by_foreign')->references('id')->on('users')->nullOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `payment_reminders`
                    ADD CONSTRAINT `payment_reminders_trigger_check` CHECK (`trigger` IN ('manual', 'auto'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
