<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `lc_loans` (LC): migration 87 of 92, dependency level 10.
 * PAD or LTR loan: the bank paid the supplier for an LC and the company repays it with interest.
 * Depends on: companies, lc_payments, letters_of_credit, money_accounts, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_loans', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('PAD or LTR loan: the bank paid the supplier for an LC and the company repays it with interest.');

            $table->id()->comment('Primary key, auto-increment');
            $table->unsignedBigInteger('company_id')->comment('Tenant owner. Every query is scoped by it.');
            $table->unsignedBigInteger('letter_of_credit_id');
            $table->unsignedBigInteger('lc_payment_id')->comment('The financed payment');
            $table->unsignedBigInteger('money_account_id')->comment('The lending bank');
            $table->string('loan_number', 40)->comment('<LC number>/L<n>');
            $table->string('loan_kind', 30)->default('pad')->comment('One of: pad, ltr. PAD when the bank pays against documents; converted to LTR on agreed terms');
            $table->decimal('principal', 18, 2);
            $table->date('start_date');
            $table->date('due_date');
            $table->decimal('interest_rate_pct', 7, 4);
            $table->string('day_count', 30)->default('360')->comment('One of: 360, 365');
            $table->date('interest_from')->comment('Interest is due from this date on the outstanding principal (start, then the last repayment or accrual)');
            $table->date('converted_on')->nullable();
            $table->string('status', 30)->default('open')->comment('One of: open, repaid, reversed');
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->unique(['company_id', 'loan_number'], 'lc_loans_company_id_loan_number_unique');
            $table->unique(['lc_payment_id'], 'lc_loans_lc_payment_id_unique');
            $table->index(['company_id', 'status', 'due_date'], 'lc_loans_company_id_status_due_date_index');
            $table->index(['letter_of_credit_id'], 'lc_loans_letter_of_credit_id_index');
            $table->index(['money_account_id'], 'lc_loans_money_account_id_index');
            $table->index(['created_by'], 'lc_loans_created_by_index');
            $table->index(['updated_by'], 'lc_loans_updated_by_index');

            $table->foreign('company_id', 'lc_loans_company_id_foreign')->references('id')->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('letter_of_credit_id', 'lc_loans_letter_of_credit_id_foreign')->references('id')->on('letters_of_credit')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('lc_payment_id', 'lc_loans_lc_payment_id_foreign')->references('id')->on('lc_payments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('money_account_id', 'lc_loans_money_account_id_foreign')->references('id')->on('money_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'lc_loans_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'lc_loans_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `lc_loans`
                    ADD CONSTRAINT `lc_loans_loan_kind_check` CHECK (`loan_kind` IN ('pad', 'ltr')),
                    ADD CONSTRAINT `lc_loans_day_count_check` CHECK (`day_count` IN ('360', '365')),
                    ADD CONSTRAINT `lc_loans_status_check` CHECK (`status` IN ('open', 'repaid', 'reversed')),
                    ADD CONSTRAINT `lc_loans_rule1_check` CHECK (`principal` > 0),
                    ADD CONSTRAINT `lc_loans_rule2_check` CHECK (`interest_rate_pct` >= 0),
                    ADD CONSTRAINT `lc_loans_rule3_check` CHECK (`due_date` >= `start_date`)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_loans');
    }
};
