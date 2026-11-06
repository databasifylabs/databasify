<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Passkey;
use App\Support\Concurrency\WorkerSafeForkDriver;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);
        DevCommands::artisan('schedule:work', 'scheduler');
        Concurrency::extend('fork', fn (): WorkerSafeForkDriver => new WorkerSafeForkDriver);
    }
}
