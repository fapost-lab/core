<?php

declare(strict_types=1);

use App\Jobs\Media\CleanupSoftDeletedMediaJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('logs:prune-flow')->dailyAt('03:00');
Schedule::command('conversations:prune')->dailyAt('03:15');
Schedule::command('logs:create-partition')->monthlyOn(1, '00:00');
Schedule::job(new CleanupSoftDeletedMediaJob())->dailyAt('03:30');
Schedule::command('flow:sweep-subflow-timeouts')->everyMinute()->withoutOverlapping();
Schedule::command('flow:sweep-overdue-automation-runs')->everyMinute()->withoutOverlapping();
Schedule::command('flow:fire-scheduled-triggers')->everyMinute()->withoutOverlapping();
Schedule::command('flow:rebuild-schedule-registry')->hourly()->withoutOverlapping();
Schedule::command('support-access:prune')->dailyAt('03:45');
Schedule::command('limit-refusals:prune')->dailyAt('04:00');
Schedule::command('audit:prune')->dailyAt('04:15');
Schedule::command('credentials:refresh-oauth2-grants')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('api-tokens:notify-expiring')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('flow:prune-automation-runs')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('tenants:start-deletions')->everyMinute()->withoutOverlapping();
