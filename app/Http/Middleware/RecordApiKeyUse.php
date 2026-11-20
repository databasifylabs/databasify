<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Queries\ApiKeys\CurrentApiKeyQuery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecordApiKeyUse
{
    private const int EVERY_SECONDS = 60;

    public function __construct(private CurrentApiKeyQuery $currentKey) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request): void
    {
        $key = $this->currentKey->for($request);

        if ($key === null || $key->last_used_at?->isAfter(now()->subSeconds(self::EVERY_SECONDS))) {
            return;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
