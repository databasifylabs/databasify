<?php

declare(strict_types=1);

use App\Enums\Api\ApiArea;
use App\Http\Controllers\Api\V1\MoveController;
use App\Http\Controllers\Api\V1\ProjectController;
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
});
