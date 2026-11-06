<?php

declare(strict_types=1);

use App\Http\Controllers\Storage\StorageProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('storage-providers', [StorageProviderController::class, 'index'])->name('storage-providers.index');
    Route::get('storage-providers/create', [StorageProviderController::class, 'create'])->name('storage-providers.create');
    Route::post('storage-providers', [StorageProviderController::class, 'store'])->name('storage-providers.store');
    Route::get('storage-providers/{provider}/edit', [StorageProviderController::class, 'edit'])->name('storage-providers.edit');
    Route::put('storage-providers/{provider}', [StorageProviderController::class, 'update'])->name('storage-providers.update');
    Route::post('storage-providers/{provider}/test', [StorageProviderController::class, 'test'])->name('storage-providers.test');
    Route::delete('storage-providers/{provider}', [StorageProviderController::class, 'destroy'])->name('storage-providers.destroy');
});
