<?php

declare(strict_types=1);

use App\Enums\Api\ApiArea;
use App\Http\Controllers\Api\V1\DnsProviderController;
use App\Http\Controllers\Api\V1\MoveController;
use App\Http\Controllers\Api\V1\NetworkController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ServerProviderCatalogController;
use App\Http\Controllers\Api\V1\ServerProviderController;
use App\Http\Controllers\Api\V1\StorageProviderController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'throttle:api'])->name('api.v1.')->group(function () {
    Route::get('system', SystemController::class)
        ->middleware(ApiArea::System->readMiddleware())
        ->name('system');

    Route::get('projects', [ProjectController::class, 'index'])
        ->middleware(ApiArea::Projects->readMiddleware())
        ->name('projects.index');

    Route::middleware(ApiArea::Projects->writeMiddleware())->group(function () {
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->whereUuid('project')->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->whereUuid('project')->name('projects.destroy');
        Route::post('projects/{project}/moves', MoveController::class)->whereUuid('project')->name('moves.store');
    });

    Route::middleware(ApiArea::ServerProviders->readMiddleware())->group(function () {
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

    Route::middleware(ApiArea::ServerProviders->writeMiddleware())->group(function () {
        Route::post('projects/{project}/server-providers', [ServerProviderController::class, 'store'])->whereUuid('project')->name('server-providers.store');
        Route::put('server-providers/{provider}', [ServerProviderController::class, 'update'])->whereUuid('provider')->name('server-providers.update');
        Route::delete('server-providers/{provider}', [ServerProviderController::class, 'destroy'])->whereUuid('provider')->name('server-providers.destroy');
        Route::delete('networks/{network}', [NetworkController::class, 'destroy'])->whereUuid('network')->name('networks.destroy');
    });

    Route::middleware(ApiArea::DnsProviders->readMiddleware())->group(function () {
        Route::get('dns-providers', [DnsProviderController::class, 'index'])->name('dns-providers.index');
        Route::get('projects/{project}/dns-providers', [DnsProviderController::class, 'index'])->whereUuid('project')->name('projects.dns-providers.index');
        Route::get('dns-providers/types', [DnsProviderController::class, 'types'])->name('dns-providers.types');
        Route::get('dns-providers/{provider}', [DnsProviderController::class, 'show'])->whereUuid('provider')->name('dns-providers.show');
    });

    Route::middleware(ApiArea::DnsProviders->writeMiddleware())->group(function () {
        Route::post('projects/{project}/dns-providers', [DnsProviderController::class, 'store'])->whereUuid('project')->name('dns-providers.store');
        Route::put('dns-providers/{provider}', [DnsProviderController::class, 'update'])->whereUuid('provider')->name('dns-providers.update');
        Route::post('dns-providers/{provider}/refresh', [DnsProviderController::class, 'refresh'])->whereUuid('provider')->name('dns-providers.refresh');
        Route::delete('dns-providers/{provider}', [DnsProviderController::class, 'destroy'])->whereUuid('provider')->name('dns-providers.destroy');
    });

    Route::middleware(ApiArea::StorageProviders->readMiddleware())->group(function () {
        Route::get('storage-providers', [StorageProviderController::class, 'index'])->name('storage-providers.index');
        Route::get('projects/{project}/storage-providers', [StorageProviderController::class, 'index'])->whereUuid('project')->name('projects.storage-providers.index');
        Route::get('storage-providers/{provider}', [StorageProviderController::class, 'show'])->whereUuid('provider')->name('storage-providers.show');
    });

    Route::middleware(ApiArea::StorageProviders->writeMiddleware())->group(function () {
        Route::post('projects/{project}/storage-providers', [StorageProviderController::class, 'store'])->whereUuid('project')->name('storage-providers.store');
        Route::put('storage-providers/{provider}', [StorageProviderController::class, 'update'])->whereUuid('provider')->name('storage-providers.update');
        Route::post('storage-providers/{provider}/test', [StorageProviderController::class, 'test'])->whereUuid('provider')->name('storage-providers.test');
        Route::delete('storage-providers/{provider}', [StorageProviderController::class, 'destroy'])->whereUuid('provider')->name('storage-providers.destroy');
    });
});
