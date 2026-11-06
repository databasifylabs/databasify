<?php

declare(strict_types=1);

use App\Http\Controllers\Servers\ServerController;
use App\Http\Controllers\Servers\ServerNetworkController;
use App\Http\Controllers\Servers\ServerOperationController;
use App\Http\Controllers\Servers\ServerTerminalController;
use App\Http\Controllers\Servers\ServerUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('servers', ServerController::class)->except(['edit', 'update']);
    Route::post('servers/{server}/operations/{operation}', [ServerOperationController::class, 'store'])->name('servers.operations.store');
    Route::post('servers/{server}/provision', [ServerOperationController::class, 'provision'])->name('servers.provision');
    Route::post('servers/{server}/refresh', [ServerOperationController::class, 'refresh'])->name('servers.refresh');
    Route::put('servers/{server}/network', [ServerNetworkController::class, 'update'])->name('servers.network.update');
    Route::post('servers/{server}/terminals', [ServerTerminalController::class, 'store'])->name('servers.terminals.store');
    Route::post('servers/{server}/terminals/{terminal}/input', [ServerTerminalController::class, 'input'])->name('servers.terminals.input');
    Route::delete('servers/{server}/terminals/{terminal}', [ServerTerminalController::class, 'destroy'])->name('servers.terminals.destroy');
    Route::post('servers/{server}/users', [ServerUserController::class, 'store'])->name('servers.users.store');
    Route::delete('servers/{server}/users/{user}', [ServerUserController::class, 'destroy'])->name('servers.users.destroy')->scopeBindings();
});
