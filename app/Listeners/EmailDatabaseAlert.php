<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DatabaseAlertRaised;
use App\Models\User;
use App\Notifications\DatabaseAlert;
use Illuminate\Support\Facades\Notification;

final class EmailDatabaseAlert
{
    public function handle(DatabaseAlertRaised $alert): void
    {
        Notification::send(User::query()->get(), DatabaseAlert::from($alert));
    }
}
