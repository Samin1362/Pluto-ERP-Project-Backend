<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly books consistency check (NFR-042, 11 §6; P4.1-BE-08): logs the result; exit 1 is visible in schedule:list output and logs.
Schedule::command('pluto:integrity')->dailyAt('02:00')->timezone('Asia/Dhaka')->withoutOverlapping();
