<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `companies` (Platform): migration 4 of 83, dependency level 1.
 * A business (tenant). All business data hangs off a company.
 * Depends on: currencies, users.
 *
 * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).
 * After the first deployment, never edit this file: add a new migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');
            $table->comment('A business (tenant). All business data hangs off a company.');

            $table->id()->comment('Primary key, auto-increment');
            $table->string('legal_name', 190);
            $table->string('trading_name', 190)->nullable();
            $table->string('business_type', 30)->comment('One of: private_limited, sole_proprietorship, partnership, public_limited');
            $table->string('trade_licence_no', 60)->nullable();
            $table->string('tax_registration_no', 40)->nullable()->comment('BIN/TIN as entered; format to be confirmed');
            $table->date('incorporated_on')->nullable();
            $table->string('registered_address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('website', 190)->nullable();
            $table->string('logo_path', 255)->nullable()->comment('Storage path of logo image');
            $table->char('base_currency_code', 3)->comment('Locked once any journal entry exists');
            $table->unsignedTinyInteger('fy_start_month')->default(7)->comment('1-12; July for Bangladesh FY');
            $table->string('timezone', 40)->default('Asia/Dhaka');
            $table->string('status', 30)->default('active')->comment('One of: active, suspended, archived');
            $table->unsignedSmallInteger('seat_limit')->default(10)->comment('Users allowed (K8 shows 7 of 10)');
            $table->unsignedInteger('lock_version')->default(1)->comment('Optimistic concurrency counter; incremented on every update (If-Match)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User who created the row');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('User who last changed the row');
            $table->dateTime('created_at')->nullable()->comment('Set by Laravel');
            $table->dateTime('updated_at')->nullable()->comment('Set by Laravel');

            $table->index(['base_currency_code'], 'companies_base_currency_code_index');
            $table->index(['created_by'], 'companies_created_by_index');
            $table->index(['updated_by'], 'companies_updated_by_index');

            $table->foreign('base_currency_code', 'companies_base_currency_code_foreign')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'companies_created_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'companies_updated_by_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE `companies`
                    ADD CONSTRAINT `companies_business_type_check` CHECK (`business_type` IN ('private_limited', 'sole_proprietorship', 'partnership', 'public_limited')),
                    ADD CONSTRAINT `companies_status_check` CHECK (`status` IN ('active', 'suspended', 'archived'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
