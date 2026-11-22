<?php

declare(strict_types=1);

use App\Http\Controllers\ConnectedApps\AuthorizeConnectedAppController;
use App\Http\Controllers\ConnectedApps\ConnectedAppController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('oauth')->name('passport.authorizations.')->group(function () {
    Route::get('authorize', [AuthorizeConnectedAppController::class, 'authorize'])
        ->middleware(RequirePassword::class)
        ->name('authorize');
    Route::post('authorize', [AuthorizeConnectedAppController::class, 'approve'])->name('approve');
    Route::delete('authorize', [AuthorizeConnectedAppController::class, 'deny'])->name('deny');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('connected-apps', [ConnectedAppController::class, 'index'])->name('connected-apps.index');
    Route::delete('connected-apps/{connectedApp}', [ConnectedAppController::class, 'destroy'])->whereUuid('connectedApp')->name('connected-apps.destroy');
});
