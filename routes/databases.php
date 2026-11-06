<?php

declare(strict_types=1);

use App\Http\Controllers\Databases\ConnectionPoolController;
use App\Http\Controllers\Databases\DatabaseController;
use App\Http\Controllers\Databases\DatabaseInsightsController;
use App\Http\Controllers\Databases\DatabaseMigrationController;
use App\Http\Controllers\Databases\DatabaseNodeController;
use App\Http\Controllers\Databases\DatabaseOperationController;
use App\Http\Controllers\Databases\DatabaseRecoveryController;
use App\Http\Controllers\Databases\DatabaseSettingsController;
use App\Http\Controllers\Databases\DatabaseUserController;
use App\Http\Controllers\Databases\SchemaController;
use App\Http\Controllers\Databases\TrustedSourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('databases', DatabaseController::class)->except(['edit', 'update']);
    Route::post('databases/{database}/failover', [DatabaseOperationController::class, 'failover'])->name('databases.failover');
    Route::post('databases/{database}/refresh', [DatabaseOperationController::class, 'refresh'])->name('databases.refresh');
    Route::post('databases/{database}/upgrade-check', [DatabaseOperationController::class, 'checkUpgrade'])->name('databases.upgrade-check');
    Route::post('databases/{database}/upgrade', [DatabaseOperationController::class, 'upgrade'])->name('databases.upgrade');
    Route::post('databases/{database}/recover', [DatabaseOperationController::class, 'recover'])->name('databases.recover');
    Route::post('databases/{database}/retune', [DatabaseOperationController::class, 'retune'])->name('databases.retune');
    Route::post('databases/{database}/reboot', [DatabaseOperationController::class, 'reboot'])->name('databases.reboot');
    Route::post('databases/{database}/stop', [DatabaseOperationController::class, 'stop'])->name('databases.stop');
    Route::post('databases/{database}/retry-setup', [DatabaseOperationController::class, 'retrySetup'])->name('databases.retry-setup');
    Route::put('databases/{database}/domain', [DatabaseSettingsController::class, 'domain'])->name('databases.domain');
    Route::put('databases/{database}/config', [DatabaseSettingsController::class, 'config'])->name('databases.config');
    Route::put('databases/{database}/persistence', [DatabaseSettingsController::class, 'persistence'])->name('databases.persistence');
    Route::put('databases/{database}/maintenance', [DatabaseSettingsController::class, 'maintenance'])->name('databases.maintenance');
    Route::post('databases/{database}/recovery', [DatabaseRecoveryController::class, 'store'])->name('databases.recovery.store');
    Route::put('databases/{database}/recovery', [DatabaseRecoveryController::class, 'update'])->name('databases.recovery.update');
    Route::delete('databases/{database}/recovery', [DatabaseRecoveryController::class, 'destroy'])->name('databases.recovery.destroy');
    Route::post('databases/{database}/recovery/backup', [DatabaseRecoveryController::class, 'backup'])->name('databases.recovery.backup');
    Route::post('databases/{database}/recovery/check', [DatabaseRecoveryController::class, 'check'])->name('databases.recovery.check');
    Route::post('databases/{database}/recovery/restore', [DatabaseRecoveryController::class, 'restore'])->name('databases.recovery.restore');
    Route::get('databases/{database}/metrics/history', [DatabaseInsightsController::class, 'history'])->name('databases.metrics.history');

    Route::scopeBindings()->group(function () {
        Route::post('databases/{database}/nodes', [DatabaseNodeController::class, 'store'])->name('databases.nodes.store');
        Route::get('databases/{database}/nodes/{node}', [DatabaseNodeController::class, 'show'])->name('databases.nodes.show');
        Route::put('databases/{database}/nodes/{node}', [DatabaseNodeController::class, 'update'])->name('databases.nodes.update');
        Route::delete('databases/{database}/nodes/{node}', [DatabaseNodeController::class, 'destroy'])->name('databases.nodes.destroy');
        Route::get('databases/{database}/nodes/{node}/logs', [DatabaseInsightsController::class, 'logs'])->name('databases.nodes.logs');
        Route::get('databases/{database}/nodes/{node}/metrics/history', [DatabaseInsightsController::class, 'nodeHistory'])->name('databases.nodes.metrics.history');
        Route::post('databases/{database}/users', [DatabaseUserController::class, 'store'])->name('databases.users.store');
        Route::post('databases/{database}/users/{user}/password', [DatabaseUserController::class, 'resetPassword'])->name('databases.users.password');
        Route::post('databases/{database}/users/{user}/encryption', [DatabaseUserController::class, 'updateEncryption'])->name('databases.users.encryption');
        Route::delete('databases/{database}/users/{user}', [DatabaseUserController::class, 'destroy'])->name('databases.users.destroy');
        Route::post('databases/{database}/schemas', [SchemaController::class, 'store'])->name('databases.schemas.store');
        Route::delete('databases/{database}/schemas/{schema}', [SchemaController::class, 'destroy'])->name('databases.schemas.destroy');
        Route::post('databases/{database}/migrations/check', [DatabaseMigrationController::class, 'check'])->name('databases.migrations.check');
        Route::post('databases/{database}/migrations', [DatabaseMigrationController::class, 'store'])->name('databases.migrations.store');
        Route::post('databases/{database}/migrations/{migration}/finish', [DatabaseMigrationController::class, 'finish'])->name('databases.migrations.finish');
        Route::delete('databases/{database}/migrations/{migration}', [DatabaseMigrationController::class, 'destroy'])->name('databases.migrations.destroy');
        Route::post('databases/{database}/pools', [ConnectionPoolController::class, 'store'])->name('databases.pools.store');
        Route::delete('databases/{database}/pools/{connectionPool}', [ConnectionPoolController::class, 'destroy'])->name('databases.pools.destroy');
        Route::post('databases/{database}/trusted-sources', [TrustedSourceController::class, 'store'])->name('databases.trusted-sources.store');
        Route::delete('databases/{database}/trusted-sources/{trustedSource}', [TrustedSourceController::class, 'destroy'])->name('databases.trusted-sources.destroy');
    });
});
