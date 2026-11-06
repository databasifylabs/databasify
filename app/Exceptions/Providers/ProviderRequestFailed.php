<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ProviderRequestFailed extends RuntimeException
{
    public static function from(string $provider, int $status, ?string $reason): self
    {
        return new self(trim("{$provider} answered {$status}. ".($reason ?? '')));
    }

    public static function unreachable(string $provider): self
    {
        return new self("{$provider} could not be reached.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->inertia()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withErrors(['provider' => $this->getMessage()]);
    }
}
