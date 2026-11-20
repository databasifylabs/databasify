<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Api\ApiArea;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\Network;
use App\Models\Passkey;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\StorageProvider;
use App\Queries\ApiKeys\CurrentApiKeyQuery;
use App\Support\Projects\ProjectContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ProjectContext::class);

        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);
        Passport::useTokenModel(ApiKey::class);
        Passport::tokensCan(ApiArea::scopes());

        Relation::enforceMorphMap([
            'server' => Server::class,
            'database' => Database::class,
            'network' => Network::class,
            'server-provider' => ServerProvider::class,
            'dns-provider' => DnsProvider::class,
            'storage-provider' => StorageProvider::class,
        ]);

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by(
            $this->app->make(CurrentApiKeyQuery::class)->for($request)->id ?? (string) $request->ip(),
        ));

        DevCommands::artisan('schedule:work', 'scheduler');

        Route::macro('project', fn (): RouteRegistrar => Route::middleware(['auth', 'verified', 'project'])
            ->prefix('{project}')
            ->whereUuid('project'));
    }
}
