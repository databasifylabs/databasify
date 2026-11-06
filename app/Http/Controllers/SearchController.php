<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Search\SearchEverything;
use App\DTOs\Search\SearchResult;
use App\Http\Requests\Search\SearchRequest;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchEverything $search): JsonResponse
    {
        return response()->json([
            'results' => $search->handle($request->searchTerm())->map(fn (SearchResult $result): array => $result->toArray())->values(),
        ]);
    }
}
