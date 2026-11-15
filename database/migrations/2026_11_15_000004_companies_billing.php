<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): subscription tracking (PEP-503) for databases created before it. New databases get
 * these columns from the generated companies migration; this adds them to existing ones. Every existing company starts
 * "not billed" (paid_until NULL), so nothing is suspended until the software owner sets its terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'paid_until')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->decimal('billing_price', 18, 2)->nullable()->after('brand_color')
                    ->comment('Monthly price in BDT the customer pays (PEP-503); NULL with paid_until NULL = not billed');
                $t->date('paid_until')->nullable()->after('billing_price')
                    ->comment('Subscription paid up to this day (PEP-503); NULL = not billed, never suspended for payment');
                $t->unsignedTinyInteger('grace_days')->default(7)->after('paid_until')
                    ->comment('Days after paid_until before the company is suspended for payment (PEP-503)');
                $t->boolean('billing_suspended')->default(false)->after('grace_days')
                    ->comment('1 when the company was suspended automatically for payment; a payment reopens it (PEP-503)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'paid_until')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['billing_price', 'paid_until', 'grace_days', 'billing_suspended']));
        }
    }
};
