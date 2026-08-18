<?php

use App\Models\Business;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('vehicle:check-reminders')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('subscriptions:process')->dailyAt('00:01')->withoutOverlapping();
Schedule::call(fn () => Business::syncEndedPlanStatuses())
    ->name('subscriptions:sync-ended-plans')
    ->dailyAt('00:05')
    ->withoutOverlapping();
