<?php

declare(strict_types=1);

namespace App\DTOs\Search;

use App\Models\Database;
use App\Models\Server;

final readonly class SearchResult
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $subtitle,
        public string $url,
    ) {}

    public static function fromDatabase(Database $database): self
    {
        return new self('database', $database->name, $database->versionLabel(), route('databases.show', $database));
    }

    public static function fromServer(Server $server): self
    {
        return new self('server', $server->name, $server->public_ip ?? 'No address yet', route('servers.show', $server));
    }

    /**
     * @return array{kind: string, title: string, subtitle: string, url: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'subtitle' => $this->subtitle, 'url' => $this->url];
    }
}
