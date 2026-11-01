<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly books consistency check (NFR-042, 11 §6; P4.1-BE-08): logs the result; exit 1 is visible in schedule:list output and logs.
Schedule::command('pluto:integrity')->dailyAt('02:00')->timezone('Asia/Dhaka')->withoutOverlapping();

// Open the next financial year 30 days before the current one ends (R-05, 06 §16; P7.1-BE-07).
Schedule::command('pluto:open-next-year')->dailyAt('01:30')->timezone('Asia/Dhaka')->withoutOverlapping();

// Recurring income and expenses become drafts on their date, never posted (BR-048; P13.1-BE-04).
Schedule::command('pluto:recurring:run')->dailyAt('06:00')->timezone('Asia/Dhaka')->withoutOverlapping();

// Overdue invoices and bills due in 3 days (in-app, e-mail if switched on), then the daily summary e-mails (11 §6; P14.1-BE-04).
Schedule::command('pluto:alerts:run')->dailyAt('07:30')->timezone('Asia/Dhaka')->withoutOverlapping();
foreach (['07:30', '08:00', '09:00'] as $at) { // each user's chosen time (K12; P15.1-BE-08)
    Schedule::command("pluto:alerts:run --summary --at={$at}")->dailyAt($at)->timezone('Asia/Dhaka')->withoutOverlapping();
}

// Export files expire 24 hours after the job finishes (P14.1-BE-05).
Schedule::command('pluto:exports:prune')->hourly()->withoutOverlapping();

// Per-company backups (K15; 11 §6; P15.1-BE-09).
Schedule::command('pluto:backups:run')->dailyAt('00:30')->timezone('Asia/Dhaka')->withoutOverlapping();
