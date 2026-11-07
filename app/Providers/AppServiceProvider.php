<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\Network;
use App\Models\Passkey;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\StorageProvider;
use App\Support\Concurrency\WorkerSafeForkDriver;
use App\Support\Projects\ProjectContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ProjectContext::class);
    }

    public function boot(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);

        Relation::enforceMorphMap([
            'server' => Server::class,
            'database' => Database::class,
            'network' => Network::class,
            'server-provider' => ServerProvider::class,
            'dns-provider' => DnsProvider::class,
            'storage-provider' => StorageProvider::class,
        ]);

        DevCommands::artisan('schedule:work', 'scheduler');

        Concurrency::extend('fork', fn (): WorkerSafeForkDriver => new WorkerSafeForkDriver);

        Route::macro('project', fn (): RouteRegistrar => Route::middleware(['auth', 'verified', 'project'])
            ->prefix('{project}')
            ->whereUuid('project'));
    }
}
