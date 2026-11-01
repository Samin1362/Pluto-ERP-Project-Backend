<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-written (not generated): P15.1-BE-08 keeps the daily summary time (K12) on the user's daily_summary row. New
 * databases get the column from the generated create migration; this adds it to databases created before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notification_preferences', 'send_at')) {
            Schema::table('notification_preferences', fn (Blueprint $t) => $t->string('send_at', 5)->nullable()->after('sms')
                ->comment('daily_summary only: HH:MM in Asia/Dhaka (07:30, 08:00 or 09:00; K12)'));
        }
    }

    public function down(): void
    {
        // The generated create migration owns the column on new databases; nothing to undo here.
    }
};
