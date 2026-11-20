<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Networks\DeleteNetwork;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NetworkResource;
use App\Models\Network;
use App\Queries\Networks\NetworkQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class NetworkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return NetworkResource::collection(NetworkQuery::withUsage()->cursorPaginate());
    }

    public function destroy(Request $request, Network $network, DeleteNetwork $delete): Response
    {
        $delete->handle($network, $request->boolean('destroy_at_provider'));

        return response()->noContent();
    }
}
