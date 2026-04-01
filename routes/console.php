<?php

use App\Jobs\DecayConfidenceJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(DecayConfidenceJob::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
