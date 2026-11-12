<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Updates\HostUpdater;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureUpdaterIsInstalled
{
    public function __construct(private HostUpdater $updater) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->updater->isInstalled(), 404);

        return $next($request);
    }
}
