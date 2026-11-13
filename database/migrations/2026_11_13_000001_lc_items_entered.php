<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): items on a letter of credit (PEP-487) for databases created before it. New databases
 * get letters_of_credit.items_entered from the generated create migration; this adds it to existing ones. Existing LCs
 * keep 0: an LC already raised against a purchase order still reads its items from that order, and an LC without one
 * gets its items through POST /letters-of-credit/{id}/items. (The new table lc_amendment_lines has its own generated
 * create migration.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letters_of_credit') && ! Schema::hasColumn('letters_of_credit', 'items_entered')) {
            Schema::table('letters_of_credit', function (Blueprint $t) {
                $t->boolean('items_entered')->default(false)->after('purchase_order_id')
                    ->comment('1 when the linked purchase order was created from the items entered on the LC (PEP-487); 0 for an existing order or none');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('letters_of_credit', 'items_entered')) {
            Schema::table('letters_of_credit', fn (Blueprint $t) => $t->dropColumn('items_entered'));
        }
    }
};
