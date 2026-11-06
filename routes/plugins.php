<?php

declare(strict_types=1);

use App\Http\Controllers\Plugins\PluginController;
use Illuminate\Support\Facades\Route;

Route::get('plugin-assets/{plugin}/{path}', [PluginController::class, 'asset'])->where(['plugin' => '[a-z0-9-]+', 'path' => '.*'])->name('plugins.asset');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('plugins', [PluginController::class, 'index'])->name('plugins.index');
    Route::post('plugins', [PluginController::class, 'store'])->name('plugins.store');
    Route::put('plugins/{plugin}', [PluginController::class, 'update'])->name('plugins.update');
    Route::post('plugins/{plugin}/upgrade', [PluginController::class, 'upgrade'])->name('plugins.upgrade');
    Route::delete('plugins/{plugin}', [PluginController::class, 'destroy'])->name('plugins.destroy');
});
