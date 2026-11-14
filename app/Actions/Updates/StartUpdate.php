<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Queries\Updates\LatestReleaseQuery;
use App\Services\Updates\HostUpdater;
use Illuminate\Validation\ValidationException;

final readonly class StartUpdate
{
    public function __construct(private HostUpdater $updater, private LatestReleaseQuery $latestRelease) {}

    public function handle(): void
    {
        $pinned = $this->updater->pinnedVersion();
        $reason = match (true) {
            $pinned !== null => "This install is pinned to {$pinned} in /opt/databasify/.env.",
            $this->updater->isRequested(), $this->updater->status()?->isRunning() === true => 'An update is already running.',
            ! $this->latestRelease->hasNewerRelease() => 'Databasify is already up to date.',
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['update' => $reason]);
        }

        $this->updater->requestUpdate();
    }
}
