<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServerProvider;
use App\Services\Providers\ProviderCatalog;
use Illuminate\Http\JsonResponse;

class ServerProviderCatalogController extends Controller
{
    public function __construct(private readonly ProviderCatalog $catalog) {}

    public function regions(ServerProvider $provider): JsonResponse
    {
        return response()->json(['data' => $this->catalog->regionsOf($provider)]);
    }

    public function plans(ServerProvider $provider, string $region): JsonResponse
    {
        return response()->json(['data' => $this->catalog->plansIn($provider, $region)]);
    }

    public function networks(ServerProvider $provider, string $region): JsonResponse
    {
        return response()->json(['data' => $this->catalog->networksIn($provider, $region)]);
    }
}
