<?php

declare(strict_types=1);

use App\Jobs\Media\CleanupSoftDeletedMediaJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('logs:prune-flow')->dailyAt('03:00');
Schedule::command('conversations:prune')->dailyAt('03:15');
Schedule::command('logs:create-partition')->monthlyOn(1, '00:00');
Schedule::job(new CleanupSoftDeletedMediaJob())->dailyAt('03:30');
Schedule::command('flow:sweep-subflow-timeouts')->everyMinute()->withoutOverlapping();
