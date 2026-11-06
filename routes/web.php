<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RepositoryStarsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Setup\SetupController;
use App\Http\Middleware\EnsureSetupIsOpen;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['guest', EnsureSetupIsOpen::class])->group(function () {
    Route::get('setup', [SetupController::class, 'create'])->name('setup');
    Route::post('setup', [SetupController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('setup.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', SearchController::class)->name('search');
    Route::get('repository/stars', RepositoryStarsController::class)->name('repository.stars');
});

require __DIR__.'/settings.php';
require __DIR__.'/servers.php';
require __DIR__.'/providers.php';
require __DIR__.'/dns.php';
require __DIR__.'/storage.php';
require __DIR__.'/databases.php';
require __DIR__.'/plugins.php';
