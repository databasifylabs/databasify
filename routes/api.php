<?php

declare(strict_types=1);

use App\Enums\Access\AccessArea;
use App\Http\Controllers\Api\V1\ConnectionPoolController;
use App\Http\Controllers\Api\V1\DatabaseController;
use App\Http\Controllers\Api\V1\DatabaseExtensionController;
use App\Http\Controllers\Api\V1\DatabaseInsightsController;
use App\Http\Controllers\Api\V1\DatabaseLogController;
use App\Http\Controllers\Api\V1\DatabaseMigrationController;
use App\Http\Controllers\Api\V1\DatabaseNodeController;
use App\Http\Controllers\Api\V1\DatabaseOperationController;
use App\Http\Controllers\Api\V1\DatabaseRecoveryController;
use App\Http\Controllers\Api\V1\DatabaseSettingsController;
use App\Http\Controllers\Api\V1\DatabaseUserController;
use App\Http\Controllers\Api\V1\DnsProviderController;
use App\Http\Controllers\Api\V1\ExtensionPackageController;
use App\Http\Controllers\Api\V1\MoveController;
use App\Http\Controllers\Api\V1\NetworkController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProviderServerController;
use App\Http\Controllers\Api\V1\SchemaController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServerLogController;
use App\Http\Controllers\Api\V1\ServerNetworkController;
use App\Http\Controllers\Api\V1\ServerOperationController;
use App\Http\Controllers\Api\V1\ServerProviderCatalogController;
use App\Http\Controllers\Api\V1\ServerProviderController;
use App\Http\Controllers\Api\V1\ServerUserController;
use App\Http\Controllers\Api\V1\StorageProviderController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TrustedSourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'throttle:api'])->name('api.v1.')->group(function () {
    Route::get('system', SystemController::class)
        ->middleware(AccessArea::System->readMiddleware())
        ->name('system');

    Route::get('projects', [ProjectController::class, 'index'])
        ->middleware(AccessArea::Projects->readMiddleware())
        ->name('projects.index');

    Route::middleware(AccessArea::Projects->writeMiddleware())->group(function () {
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->whereUuid('project')->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->whereUuid('project')->name('projects.destroy');
        Route::post('projects/{project}/moves', MoveController::class)->whereUuid('project')->name('moves.store');
    });

    Route::middleware(AccessArea::ServerProviders->readMiddleware())->group(function () {
        Route::get('server-providers', [ServerProviderController::class, 'index'])->name('server-providers.index');
        Route::get('projects/{project}/server-providers', [ServerProviderController::class, 'index'])->whereUuid('project')->name('projects.server-providers.index');
        Route::get('server-providers/types', [ServerProviderController::class, 'types'])->name('server-providers.types');
        Route::get('server-providers/{provider}', [ServerProviderController::class, 'show'])->whereUuid('provider')->name('server-providers.show');
        Route::get('server-providers/{provider}/regions', [ServerProviderCatalogController::class, 'regions'])->whereUuid('provider')->name('server-providers.regions');
        Route::get('server-providers/{provider}/regions/{region}/plans', [ServerProviderCatalogController::class, 'plans'])->whereUuid('provider')->name('server-providers.plans');
        Route::get('server-providers/{provider}/regions/{region}/networks', [ServerProviderCatalogController::class, 'networks'])->whereUuid('provider')->name('server-providers.networks');
        Route::get('networks', [NetworkController::class, 'index'])->name('networks.index');
        Route::get('projects/{project}/networks', [NetworkController::class, 'index'])->whereUuid('project')->name('projects.networks.index');
    });

    Route::middleware(AccessArea::ServerProviders->writeMiddleware())->group(function () {
        Route::post('projects/{project}/server-providers', [ServerProviderController::class, 'store'])->whereUuid('project')->name('server-providers.store');
        Route::put('server-providers/{provider}', [ServerProviderController::class, 'update'])->whereUuid('provider')->name('server-providers.update');
        Route::delete('server-providers/{provider}', [ServerProviderController::class, 'destroy'])->whereUuid('provider')->name('server-providers.destroy');
        Route::delete('networks/{network}', [NetworkController::class, 'destroy'])->whereUuid('network')->name('networks.destroy');
        Route::post('server-providers/{provider}/servers', ProviderServerController::class)->whereUuid('provider')->name('server-providers.servers.store');
    });

    Route::middleware(AccessArea::DnsProviders->readMiddleware())->group(function () {
        Route::get('dns-providers', [DnsProviderController::class, 'index'])->name('dns-providers.index');
        Route::get('projects/{project}/dns-providers', [DnsProviderController::class, 'index'])->whereUuid('project')->name('projects.dns-providers.index');
        Route::get('dns-providers/types', [DnsProviderController::class, 'types'])->name('dns-providers.types');
        Route::get('dns-providers/{provider}', [DnsProviderController::class, 'show'])->whereUuid('provider')->name('dns-providers.show');
    });

    Route::middleware(AccessArea::DnsProviders->writeMiddleware())->group(function () {
        Route::post('projects/{project}/dns-providers', [DnsProviderController::class, 'store'])->whereUuid('project')->name('dns-providers.store');
        Route::put('dns-providers/{provider}', [DnsProviderController::class, 'update'])->whereUuid('provider')->name('dns-providers.update');
        Route::post('dns-providers/{provider}/refresh', [DnsProviderController::class, 'refresh'])->whereUuid('provider')->name('dns-providers.refresh');
        Route::delete('dns-providers/{provider}', [DnsProviderController::class, 'destroy'])->whereUuid('provider')->name('dns-providers.destroy');
    });

    Route::middleware(AccessArea::StorageProviders->readMiddleware())->group(function () {
        Route::get('storage-providers', [StorageProviderController::class, 'index'])->name('storage-providers.index');
        Route::get('projects/{project}/storage-providers', [StorageProviderController::class, 'index'])->whereUuid('project')->name('projects.storage-providers.index');
        Route::get('storage-providers/{provider}', [StorageProviderController::class, 'show'])->whereUuid('provider')->name('storage-providers.show');
    });

    Route::middleware(AccessArea::StorageProviders->writeMiddleware())->group(function () {
        Route::post('projects/{project}/storage-providers', [StorageProviderController::class, 'store'])->whereUuid('project')->name('storage-providers.store');
        Route::put('storage-providers/{provider}', [StorageProviderController::class, 'update'])->whereUuid('provider')->name('storage-providers.update');
        Route::post('storage-providers/{provider}/test', [StorageProviderController::class, 'test'])->whereUuid('provider')->name('storage-providers.test');
        Route::delete('storage-providers/{provider}', [StorageProviderController::class, 'destroy'])->whereUuid('provider')->name('storage-providers.destroy');
    });

    Route::middleware(AccessArea::Servers->readMiddleware())->group(function () {
        Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
        Route::get('projects/{project}/servers', [ServerController::class, 'index'])->whereUuid('project')->name('projects.servers.index');
        Route::get('servers/ssh-key', [ServerController::class, 'sshKey'])->name('servers.ssh-key');
        Route::get('servers/{server}', [ServerController::class, 'show'])->whereUuid('server')->name('servers.show');
        Route::get('servers/{server}/users', [ServerUserController::class, 'index'])->whereUuid('server')->name('servers.users.index');
        Route::get('servers/{server}/logs', [ServerLogController::class, 'index'])->whereUuid('server')->name('servers.logs.index');
        Route::get('servers/{server}/logs/{log}', [ServerLogController::class, 'show'])->whereUuid(['server', 'log'])->name('servers.logs.show');
    });

    Route::middleware(AccessArea::Servers->writeMiddleware())->group(function () {
        Route::post('projects/{project}/servers', [ServerController::class, 'store'])->whereUuid('project')->name('servers.store');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->whereUuid('server')->name('servers.destroy');
        Route::post('servers/{server}/operations/{operation}', [ServerOperationController::class, 'run'])->whereUuid('server')->name('servers.operations.store');
        Route::post('servers/{server}/provision', [ServerOperationController::class, 'provision'])->whereUuid('server')->name('servers.provision');
        Route::post('servers/{server}/refresh', [ServerOperationController::class, 'refresh'])->whereUuid('server')->name('servers.refresh');
        Route::put('servers/{server}/network', ServerNetworkController::class)->whereUuid('server')->name('servers.network.update');
        Route::post('servers/{server}/users', [ServerUserController::class, 'store'])->whereUuid('server')->name('servers.users.store');
        Route::delete('servers/{server}/users/{user}', [ServerUserController::class, 'destroy'])->whereUuid(['server', 'user'])->scopeBindings()->name('servers.users.destroy');
    });

    Route::middleware(AccessArea::Databases->readMiddleware())->group(function () {
        Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::get('projects/{project}/databases', [DatabaseController::class, 'index'])->whereUuid('project')->name('projects.databases.index');
        Route::get('databases/engines', [DatabaseController::class, 'engines'])->name('databases.engines');
        Route::get('databases/{database}', [DatabaseController::class, 'show'])->whereUuid('database')->name('databases.show');

        Route::whereUuid(['database', 'node'])->scopeBindings()->group(function () {
            Route::get('databases/{database}/nodes', [DatabaseNodeController::class, 'index'])->name('databases.nodes.index');
            Route::get('databases/{database}/nodes/{node}', [DatabaseNodeController::class, 'show'])->name('databases.nodes.show');
            Route::get('databases/{database}/metrics/history', [DatabaseInsightsController::class, 'history'])->name('databases.metrics.history');
            Route::get('databases/{database}/nodes/{node}/metrics/history', [DatabaseInsightsController::class, 'nodeHistory'])->name('databases.nodes.metrics.history');
            Route::get('databases/{database}/nodes/{node}/logs', [DatabaseInsightsController::class, 'nodeLogs'])->name('databases.nodes.logs');
            Route::get('databases/{database}/users', [DatabaseUserController::class, 'index'])->name('databases.users.index');
            Route::get('databases/{database}/schemas', [SchemaController::class, 'index'])->name('databases.schemas.index');
            Route::get('databases/{database}/recovery', [DatabaseRecoveryController::class, 'show'])->name('databases.recovery.show');
            Route::get('databases/{database}/migrations', [DatabaseMigrationController::class, 'index'])->name('databases.migrations.index');
            Route::get('databases/{database}/logs', [DatabaseLogController::class, 'index'])->name('databases.logs.index');
            Route::get('databases/{database}/logs/{log}', [DatabaseLogController::class, 'show'])->whereUuid('log')->name('databases.logs.show');
            Route::get('databases/{database}/pools', [ConnectionPoolController::class, 'index'])->name('databases.pools.index');
            Route::get('databases/{database}/extensions', [DatabaseExtensionController::class, 'available'])->name('databases.extensions.available');
            Route::get('databases/{database}/extension-packages', [ExtensionPackageController::class, 'index'])->name('databases.extension-packages.index');
            Route::get('databases/{database}/extension-packages/{package}/candidate', [ExtensionPackageController::class, 'candidate'])->name('databases.extension-packages.candidate');
            Route::get('databases/{database}/schemas/{schema}/extensions', [DatabaseExtensionController::class, 'index'])->scopeBindings()->name('databases.schemas.extensions.index');
            Route::get('databases/{database}/nodes/{node}/pools', [ConnectionPoolController::class, 'nodeIndex'])->name('databases.nodes.pools.index');
            Route::get('databases/{database}/trusted-sources', [TrustedSourceController::class, 'index'])->name('databases.trusted-sources.index');
            Route::get('databases/{database}/nodes/{node}/trusted-sources', [TrustedSourceController::class, 'nodeIndex'])->name('databases.nodes.trusted-sources.index');
        });
    });

    Route::middleware(AccessArea::Databases->writeMiddleware())->group(function () {
        Route::post('projects/{project}/databases', [DatabaseController::class, 'store'])->whereUuid('project')->name('databases.store');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->whereUuid('database')->name('databases.destroy');

        Route::controller(DatabaseOperationController::class)->whereUuid('database')->group(function () {
            Route::post('databases/{database}/failover', 'failover')->name('databases.failover');
            Route::post('databases/{database}/refresh', 'refresh')->name('databases.refresh');
            Route::post('databases/{database}/upgrade-check', 'checkUpgrade')->name('databases.upgrade-check');
            Route::post('databases/{database}/upgrade', 'upgrade')->name('databases.upgrade');
            Route::post('databases/{database}/recover', 'recover')->name('databases.recover');
            Route::post('databases/{database}/retune', 'retune')->name('databases.retune');
            Route::post('databases/{database}/reboot', 'reboot')->name('databases.reboot');
            Route::post('databases/{database}/retry-setup', 'retrySetup')->name('databases.retry-setup');
            Route::post('databases/{database}/stop', 'stop')->name('databases.stop');
        });

        Route::controller(DatabaseNodeController::class)->whereUuid(['database', 'node'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/nodes', 'store')->name('databases.nodes.store');
            Route::put('databases/{database}/nodes/{node}', 'update')->name('databases.nodes.update');
            Route::put('databases/{database}/nodes/{node}/config', 'config')->name('databases.nodes.config');
            Route::post('databases/{database}/nodes/{node}/reboot', 'reboot')->name('databases.nodes.reboot');
            Route::post('databases/{database}/nodes/{node}/stop', 'stop')->name('databases.nodes.stop');
            Route::post('databases/{database}/nodes/{node}/retune', 'retune')->name('databases.nodes.retune');
            Route::delete('databases/{database}/nodes/{node}', 'destroy')->name('databases.nodes.destroy');
        });

        Route::controller(DatabaseUserController::class)->whereUuid(['database', 'user'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/users', 'store')->name('databases.users.store');
            Route::get('databases/{database}/users/{user}/credentials', 'credentials')->name('databases.users.credentials');
            Route::post('databases/{database}/users/{user}/password', 'resetPassword')->name('databases.users.password');
            Route::post('databases/{database}/users/{user}/encryption', 'updateEncryption')->name('databases.users.encryption');
            Route::delete('databases/{database}/users/{user}', 'destroy')->name('databases.users.destroy');
        });

        Route::controller(SchemaController::class)->whereUuid(['database', 'schema'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/schemas', 'store')->name('databases.schemas.store');
            Route::delete('databases/{database}/schemas/{schema}', 'destroy')->name('databases.schemas.destroy');
        });

        Route::controller(ConnectionPoolController::class)->whereUuid(['database', 'node', 'connectionPool'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/pools', 'store')->name('databases.pools.store');
            Route::delete('databases/{database}/pools/{connectionPool}', 'destroy')->name('databases.pools.destroy');
            Route::post('databases/{database}/nodes/{node}/pools', 'nodeStore')->name('databases.nodes.pools.store');
            Route::delete('databases/{database}/nodes/{node}/pools/{connectionPool}', 'nodeDestroy')->name('databases.nodes.pools.destroy');
        });

        Route::controller(ExtensionPackageController::class)->whereUuid(['database'])->group(function () {
            Route::post('databases/{database}/extension-packages', 'store')->name('databases.extension-packages.store');
            Route::delete('databases/{database}/extension-packages/{package}', 'destroy')->name('databases.extension-packages.destroy');
            Route::post('databases/{database}/extension-packages/{package}/versions', 'update')->name('databases.extension-packages.versions');
        });

        Route::controller(DatabaseExtensionController::class)->whereUuid(['database', 'schema'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/schemas/{schema}/extensions', 'store')->name('databases.schemas.extensions.store');
            Route::post('databases/{database}/schemas/{schema}/extensions/{extension}/update', 'update')->name('databases.schemas.extensions.update');
            Route::delete('databases/{database}/schemas/{schema}/extensions/{extension}', 'destroy')->name('databases.schemas.extensions.destroy');
        });

        Route::controller(TrustedSourceController::class)->whereUuid(['database', 'node', 'trustedSource'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/trusted-sources', 'store')->name('databases.trusted-sources.store');
            Route::delete('databases/{database}/trusted-sources/{trustedSource}', 'destroy')->name('databases.trusted-sources.destroy');
            Route::post('databases/{database}/nodes/{node}/trusted-sources', 'nodeStore')->name('databases.nodes.trusted-sources.store');
            Route::delete('databases/{database}/nodes/{node}/trusted-sources/{trustedSource}', 'nodeDestroy')->name('databases.nodes.trusted-sources.destroy');
        });

        Route::controller(DatabaseRecoveryController::class)->whereUuid('database')->group(function () {
            Route::post('databases/{database}/recovery', 'store')->name('databases.recovery.store');
            Route::put('databases/{database}/recovery', 'update')->name('databases.recovery.update');
            Route::delete('databases/{database}/recovery', 'destroy')->name('databases.recovery.destroy');
            Route::post('databases/{database}/recovery/backup', 'backup')->name('databases.recovery.backup');
            Route::post('databases/{database}/recovery/check', 'check')->name('databases.recovery.check');
            Route::post('databases/{database}/recovery/restore', 'restore')->name('databases.recovery.restore');
        });

        Route::controller(DatabaseMigrationController::class)->whereUuid(['database', 'migration'])->scopeBindings()->group(function () {
            Route::post('databases/{database}/migrations/check', 'check')->name('databases.migrations.check');
            Route::post('databases/{database}/migrations', 'store')->name('databases.migrations.store');
            Route::post('databases/{database}/migrations/{migration}/finish', 'finish')->name('databases.migrations.finish');
            Route::delete('databases/{database}/migrations/{migration}', 'destroy')->name('databases.migrations.destroy');
        });

        Route::controller(DatabaseSettingsController::class)->whereUuid('database')->group(function () {
            Route::put('databases/{database}/domain', 'domain')->name('databases.domain');
            Route::put('databases/{database}/config', 'config')->name('databases.config');
            Route::put('databases/{database}/persistence', 'persistence')->name('databases.persistence');
            Route::put('databases/{database}/maintenance', 'maintenance')->name('databases.maintenance');
        });
    });
});
