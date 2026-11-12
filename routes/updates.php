<?php

declare(strict_types=1);

use App\Http\Controllers\Updates\UpdateController;
use App\Http\Middleware\EnsureUpdaterIsInstalled;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', EnsureUpdaterIsInstalled::class])->group(function () {
    Route::get('updates', [UpdateController::class, 'index'])->name('updates.index');
    Route::post('updates', [UpdateController::class, 'store'])->name('updates.store');
    Route::post('updates/check', [UpdateController::class, 'check'])->name('updates.check');
    Route::get('updates/log', [UpdateController::class, 'log'])->name('updates.log');
});
