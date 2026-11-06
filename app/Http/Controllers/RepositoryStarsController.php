<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Repository\CountRepositoryStars;
use Illuminate\Http\JsonResponse;

class RepositoryStarsController extends Controller
{
    public function __invoke(CountRepositoryStars $stars): JsonResponse
    {
        return response()->json(['stars' => $stars->handle()]);
    }
}
