<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\ArchiveSettings;
use App\Engines\Data\Member;
use App\Engines\Data\Secrets;
use App\Engines\Responses\NodeConfigure as NodeConfigureReply;

/**
 * @implements EngineRequest<NodeConfigureReply>
 */
final readonly class NodeConfigure implements EngineRequest
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

    public function command(): string
    {
        return 'node configure';
    }

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

    public function reply(): string
    {
        return NodeConfigureReply::class;
    }
}
