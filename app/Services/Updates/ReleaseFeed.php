<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\DTOs\Updates\Release;
use App\Services\Http\Payload;
use Illuminate\Support\Facades\Http;

final readonly class ReleaseFeed
{
    private const int TIMEOUT_SECONDS = 10;

    public function latest(): Release
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)->get(config()->string('databasify.updates.source'))->throw();

        return Release::fromPayload(Payload::of($response->json()));
    }
}
