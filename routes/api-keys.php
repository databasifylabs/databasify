<?php

declare(strict_types=1);

use App\Http\Controllers\ApiKeys\ApiKeyController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('api-keys', [ApiKeyController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('api-keys.store');
    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
});
