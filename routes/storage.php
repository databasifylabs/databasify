<?php

declare(strict_types=1);

use App\Http\Controllers\Storage\FileBackupController;
use App\Http\Controllers\Storage\StorageProviderController;
use Illuminate\Support\Facades\Route;

Route::project()->group(function () {
    Route::get('file-backups', [FileBackupController::class, 'index'])->name('file-backups.index');
    Route::post('file-backups', [FileBackupController::class, 'store'])->name('file-backups.store');
    Route::put('file-backups/{destination}', [FileBackupController::class, 'update'])->name('file-backups.update');
    Route::post('file-backups/{destination}/backup', [FileBackupController::class, 'backup'])->name('file-backups.backup');
    Route::delete('file-backups/{destination}', [FileBackupController::class, 'destroy'])->name('file-backups.destroy');
    Route::get('storage-providers', [StorageProviderController::class, 'index'])->name('storage-providers.index');
    Route::get('storage-providers/create', [StorageProviderController::class, 'create'])->name('storage-providers.create');
    Route::post('storage-providers', [StorageProviderController::class, 'store'])->name('storage-providers.store');
    Route::get('storage-providers/{provider}/edit', [StorageProviderController::class, 'edit'])->name('storage-providers.edit');
    Route::put('storage-providers/{provider}', [StorageProviderController::class, 'update'])->name('storage-providers.update');
    Route::post('storage-providers/{provider}/test', [StorageProviderController::class, 'test'])->name('storage-providers.test');
    Route::delete('storage-providers/{provider}', [StorageProviderController::class, 'destroy'])->name('storage-providers.destroy');
});
