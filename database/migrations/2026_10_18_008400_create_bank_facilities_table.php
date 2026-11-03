<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `bank_facilities` (LC): migration 84 of 90, dependency level 5.
 * Credit lines a bank grants the company for imports: LC limit and loan (PAD/LTR) limit, with the default loan terms.
 * Depends on: companies, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_facilities', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('Credit lines a bank grants the company for imports: LC limit and loan (PAD/LTR) limit, with the default loan terms.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('money_account_id')->comment('The bank (a bank-type money account)');
            $table->decimal('lc_limit', 18, 2)->default(0)->comment('0 = no LC limit set');
            $table->decimal('loan_limit', 18, 2)->default(0)->comment('PAD and LTR together; 0 = no limit set');
            $table->string('limit_action', 30)->default('warn')->comment('One of: warn, block. What an LC submit or a financed payment over the limit does');
            $table->decimal('interest_rate_pct', 7, 4)->default(0)->comment('Default annual rate for new loans');
            $table->string('day_count', 30)->default('360')->comment('One of: 360, 365');
            $table->unsignedSmallInteger('loan_tenor_days')->default(90);
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'money_account_id'], 'bank_facilities_company_id_money_account_id_unique');
            $table->index(['money_account_id'], 'bank_facilities_money_account_id_index');
            $table->index(['created_by'], 'bank_facilities_created_by_index');
            $table->index(['updated_by'], 'bank_facilities_updated_by_index');

            $table->foreign('company_id', 'bank_facilities_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'bank_facilities_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'bank_facilities_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'bank_facilities_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `bank_facilities`
                    ADD CONSTRAINT `bank_facilities_limit_action_check` CHECK (`limit_action` IN ('warn', 'block')),
                    ADD CONSTRAINT `bank_facilities_day_count_check` CHECK (`day_count` IN ('360', '365')),
                    ADD CONSTRAINT `bank_facilities_rule1_check` CHECK (`lc_limit` >= 0),
                    ADD CONSTRAINT `bank_facilities_rule2_check` CHECK (`loan_limit` >= 0),
                    ADD CONSTRAINT `bank_facilities_rule3_check` CHECK (`interest_rate_pct` >= 0),
                    ADD CONSTRAINT `bank_facilities_rule4_check` CHECK (`loan_tenor_days` > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_facilities');
    }
};
