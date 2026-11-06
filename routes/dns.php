<?php

declare(strict_types=1);

use App\Http\Controllers\Dns\DnsProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dns', [DnsProviderController::class, 'index'])->name('dns.index');
    Route::get('dns/create', [DnsProviderController::class, 'create'])->name('dns.create');
    Route::post('dns', [DnsProviderController::class, 'store'])->name('dns.store');
    Route::post('dns/{provider}/refresh', [DnsProviderController::class, 'refresh'])->name('dns.refresh');
    Route::put('dns/{provider}', [DnsProviderController::class, 'update'])->name('dns.update');
    Route::delete('dns/{provider}', [DnsProviderController::class, 'destroy'])->name('dns.destroy');
});
