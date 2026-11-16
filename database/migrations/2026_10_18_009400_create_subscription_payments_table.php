<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `subscription_payments` (Platform): migration 94 of 97, dependency level 2.
 * Payments a customer company made for its Pluto subscription (PEP-503), recorded by the software owner in the platform console. Each extends companies.paid_until.
 * Depends on: companies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Payments a customer company made for its Pluto subscription (PEP-503), recorded by the software owner in the platform console. Each extends companies.paid_until.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->date('paid_on');
            $table->decimal('amount', 18, 2)->comment('BDT received');
            $table->unsignedTinyInteger('months')->comment('Months the payment covers');
            $table->date('paid_until')->comment('companies.paid_until after this payment');
            $table->string('method', 30)->comment('One of: bank, bkash, nagad, cash, card, other');
            $table->string('reference', 80)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['company_id', 'paid_on'], 'subscription_payments_company_id_paid_on_index');
            $table->index(['created_by'], 'subscription_payments_created_by_index');
            $table->index(['updated_by'], 'subscription_payments_updated_by_index');

            $table->foreign('company_id', 'subscription_payments_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'subscription_payments_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'subscription_payments_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscription_payments`
                    ADD CONSTRAINT `subscription_payments_method_check` CHECK (`method` IN ('bank', 'bkash', 'nagad', 'cash', 'card', 'other'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
