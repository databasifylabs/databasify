<?php

declare(strict_types=1);

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

final readonly class TransientFailure
{
    private const int TOO_MANY_REQUESTS = 429;

    /**
     * @var list<int>
     */
    private const array RETRY_DELAYS_MILLISECONDS = [200, 1000, 3000];

    public static function retrying(PendingRequest $request): PendingRequest
    {
        return $request->retry(self::RETRY_DELAYS_MILLISECONDS, 0, self::matches(...), false);
    }

    private static function matches(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->serverError() || $exception->response->status() === self::TOO_MANY_REQUESTS);
    }
}
