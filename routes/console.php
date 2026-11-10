<?php

declare(strict_types=1);

use App\Console\Commands\Databases\AdvanceDatabaseFlowsCommand;
use App\Console\Commands\Databases\ApplyMaintenanceCommand;
use App\Console\Commands\Databases\CheckDatabaseUpgradesCommand;
use App\Console\Commands\Databases\RecordDatabaseMetricsCommand;
use App\Console\Commands\Databases\RefreshDatabaseStatusCommand;
use App\Console\Commands\Databases\RefreshMigrationsCommand;
use App\Console\Commands\Databases\RefreshRecoveryStatusCommand;
use App\Console\Commands\Databases\RenewCertificatesCommand;
use App\Console\Commands\Networks\ReconcileNetworksCommand;
use App\Console\Commands\Servers\ReleaseAbandonedWorkCommand;
use App\Models\NodeMetricSample;
use App\Models\ServerLog;
use Illuminate\Support\Facades\Schedule;

Schedule::onOneServer()->group(function () {
    Schedule::command(AdvanceDatabaseFlowsCommand::class)->everyTenSeconds()->withoutOverlapping(1);
    Schedule::command(RefreshDatabaseStatusCommand::class)->everyMinute()->withoutOverlapping(5);
    Schedule::command(RecordDatabaseMetricsCommand::class)->everyMinute()->withoutOverlapping(5);
    Schedule::command(RefreshMigrationsCommand::class)->everyMinute()->withoutOverlapping(5);
    Schedule::command(RefreshRecoveryStatusCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
    Schedule::command(ReconcileNetworksCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
    Schedule::command(ReleaseAbandonedWorkCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
    Schedule::command(ApplyMaintenanceCommand::class)->hourly();
    Schedule::command(CheckDatabaseUpgradesCommand::class)->daily();
    Schedule::command(RenewCertificatesCommand::class)->dailyAt('02:00')->withoutOverlapping(240);
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
    Schedule::command('model:prune', ['--model' => [NodeMetricSample::class, ServerLog::class]])->daily();
});
