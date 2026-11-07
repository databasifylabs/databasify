<?php

declare(strict_types=1);

namespace App\Queries\Servers;

use App\Models\Database;
use App\Models\Server;
use App\Models\ServerLog;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<ServerLog>
 */
final class ServerLogQuery extends Query
{
    public const int PER_PAGE = 30;

    private const array LIST_COLUMNS = ['id', 'server_id', 'database_id', 'group_id', 'group_name', 'name', 'status', 'exit_code', 'created_at', 'updated_at'];

    private function __construct(private readonly Server|Database|null $owner) {}

    public static function of(Server|Database $owner): self
    {
        return new self($owner);
    }

    public static function latest(): self
    {
        return new self(null);
    }

    public function builder(): Builder
    {
        return $this->owned()->select(self::LIST_COLUMNS)->with(['server:id,name', 'database:id,name'])->latestFirst();
    }

    public function latestId(): ?string
    {
        return $this->owned()->latestFirst()->first(['id'])?->id;
    }

    public function selected(string $id): ?ServerLog
    {
        $query = $this->builder()->addSelect('output');

        if ($id === '') {
            return $query->first();
        }

        return $query->whereKey($id)->first();
    }

    /**
     * @return Builder<ServerLog>
     */
    private function owned(): Builder
    {
        return $this->owner === null ? ServerLog::query()->inCurrentProject() : $this->owner->serverLogs()->getQuery();
    }
}
