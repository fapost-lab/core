<?php

declare(strict_types=1);

use App\Jobs\Media\CleanupSoftDeletedMediaJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('logs:prune-flow')->dailyAt('03:00');
Schedule::command('logs:create-partition')->monthlyOn(1, '00:00');
Schedule::job(new CleanupSoftDeletedMediaJob())->dailyAt('03:30');
Schedule::command('flow:sweep-subflow-timeouts')->everyMinute()->withoutOverlapping();
