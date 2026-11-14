<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class NodeConfig
{
    /**
     * @param  list<Member>  $members
     * @param  array<string, string>  $settings
     */
    public function __construct(
        public bool $clustered,
        public bool $readOnly,
        public string $ip,
        public string $databaseId,
        public array $members,
        public array $settings,
        public Secrets $secrets,
        public ?ArchiveSettings $archive,
        public ?string $primary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'clustered' => $this->clustered,
            'readOnly' => $this->readOnly,
            'ip' => $this->ip,
            'databaseId' => $this->databaseId,
            'members' => array_map(fn (Member $member): array => $member->toPayload(), $this->members),
            'settings' => (object) $this->settings,
            'secrets' => $this->secrets->toPayload(),
            'archive' => $this->archive?->toPayload(),
            'primary' => $this->primary,
        ];
    }
}
