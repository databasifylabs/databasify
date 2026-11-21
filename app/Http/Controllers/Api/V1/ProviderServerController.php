<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Providers\OrderProviderServer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderServerRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Models\ServerProvider;
use Illuminate\Http\JsonResponse;

class ProviderServerController extends Controller
{
    public function __invoke(OrderServerRequest $request, ServerProvider $provider, OrderProviderServer $order): JsonResponse
    {
        return (new ServerResource($order->handle($request->toOrder())))->response()->setStatusCode(202);
    }
}
