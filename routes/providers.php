<?php

declare(strict_types=1);

use App\Http\Controllers\Providers\NetworkController;
use App\Http\Controllers\Providers\ProviderCatalogController;
use App\Http\Controllers\Providers\ProviderServerController;
use App\Http\Controllers\Providers\ServerProviderController;
use Illuminate\Support\Facades\Route;

Route::project()->group(function () {
    Route::get('providers', [ServerProviderController::class, 'index'])->name('providers.index');
    Route::get('providers/create', [ServerProviderController::class, 'create'])->name('providers.create');
    Route::post('providers', [ServerProviderController::class, 'store'])->name('providers.store');
    Route::put('providers/{provider}', [ServerProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [ServerProviderController::class, 'destroy'])->name('providers.destroy');
    Route::get('providers/{provider}/regions', [ProviderCatalogController::class, 'regions'])->name('providers.regions');
    Route::get('providers/{provider}/regions/{region}/plans', [ProviderCatalogController::class, 'plans'])->name('providers.plans');
    Route::get('providers/{provider}/regions/{region}/networks', [ProviderCatalogController::class, 'networks'])->name('providers.networks');
    Route::post('providers/servers', [ProviderServerController::class, 'store'])->name('providers.servers.store');
    Route::delete('networks/{network}', [NetworkController::class, 'destroy'])->name('networks.destroy');
});
