<?php

declare(strict_types=1);

namespace App\DTOs\Updates;

use Carbon\CarbonImmutable;

final readonly class UpdatesPage
{
    /**
     * @param  list<string>  $logTail
     */
    public function __construct(
        public string $currentVersion,
        public ?CheckedRelease $checkedRelease,
        public CarbonImmutable $nextCheckAt,
        public bool $updateAvailable,
        public ?string $pinnedVersion,
        public bool $updateRequested,
        public ?UpdateStatus $status,
        public array $logTail,
    ) {}

    /**
     * @return array{currentVersion: string, release: array{version: string, publishedAt: string, url: string, notesHtml: string}|null, checkedAt: string|null, nextCheckAt: string, updateAvailable: bool, pinnedVersion: string|null, updateRequested: bool, status: array{state: string, from: string, to: string, startedAt: string, finishedAt: string|null, rolledBack: bool, message: string, steps: list<array{title: string, state: string}>}|null, logTail: list<string>}
     */
    public function toArray(): array
    {
        $release = $this->checkedRelease?->release;

        return [
            'currentVersion' => $this->currentVersion,
            'release' => $release === null ? null : [
                'version' => $release->version,
                'publishedAt' => $release->publishedAt->toIso8601ZuluString(),
                'url' => $release->url,
                'notesHtml' => $release->notesHtml(),
            ],
            'checkedAt' => $this->checkedRelease?->checkedAt->toIso8601ZuluString(),
            'nextCheckAt' => $this->nextCheckAt->toIso8601ZuluString(),
            'updateAvailable' => $this->updateAvailable,
            'pinnedVersion' => $this->pinnedVersion,
            'updateRequested' => $this->updateRequested,
            'status' => $this->status?->toArray(),
            'logTail' => $this->logTail,
        ];
    }
}
