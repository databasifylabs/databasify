<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Projects\MoveController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\RepositoryStarPromptController;
use App\Http\Controllers\RepositoryStarsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Setup\SetupController;
use App\Http\Middleware\EnsureSetupIsOpen;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', EnsureSetupIsOpen::class])->group(function () {
    Route::get('setup', [SetupController::class, 'create'])->name('setup');
    Route::post('setup', [SetupController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('setup.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('dashboard', HomeController::class);
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('repository/stars', RepositoryStarsController::class)->name('repository.stars');
    Route::delete('repository/star-prompt', [RepositoryStarPromptController::class, 'destroy'])->name('repository.star-prompt.destroy');
});

Route::project()->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', SearchController::class)->name('search');
    Route::post('moves', [MoveController::class, 'store'])->name('moves.store');
});
