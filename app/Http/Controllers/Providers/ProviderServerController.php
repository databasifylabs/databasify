<?php

declare(strict_types=1);

namespace App\Http\Controllers\Providers;

use App\Actions\Providers\OrderProviderServer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Providers\StoreProviderServerRequest;
use Illuminate\Http\RedirectResponse;

class ProviderServerController extends Controller
{
    public function store(StoreProviderServerRequest $request, OrderProviderServer $order): RedirectResponse
    {
        return to_route('servers.show', $order->handle($request->toOrder()));
    }
}
