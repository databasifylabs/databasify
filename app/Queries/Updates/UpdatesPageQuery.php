<?php

declare(strict_types=1);

namespace App\Queries\Updates;

use App\DTOs\Updates\UpdatesPage;
use App\Services\Updates\HostUpdater;
use Carbon\CarbonImmutable;

final readonly class UpdatesPageQuery
{
    private const int LOG_TAIL_LINES = 20;

    public function __construct(private LatestReleaseQuery $latestRelease, private HostUpdater $updater) {}

    public function get(): UpdatesPage
    {
        $status = $this->updater->status();

        return new UpdatesPage(
            config()->string('app.version'),
            $this->latestRelease->get(),
            CarbonImmutable::tomorrow(),
            $this->latestRelease->hasNewerRelease(),
            $this->updater->pinnedVersion(),
            $this->updater->isInstalled(),
            $this->updater->isRequested(),
            $status,
            $status?->isFailed() === true ? $this->logTail() : [],
        );
    }

    /**
     * @return list<string>
     */
    private function logTail(): array
    {
        $lines = preg_split('/\R/', rtrim($this->updater->log())) ?: [];

        return array_slice(array_filter($lines, fn (string $line): bool => $line !== ''), -self::LOG_TAIL_LINES);
    }
}
