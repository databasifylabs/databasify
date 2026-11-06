<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\DTOs\Search\SearchResult;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Support\Collection;

final readonly class SearchEverything
{
    private const int LIMIT = 6;

    /**
     * @return Collection<int, SearchResult>
     */
    public function handle(string $term): Collection
    {
        return $this->databasesMatching($term)->concat($this->serversMatching($term));
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function databasesMatching(string $term): Collection
    {
        return Database::query()
            ->matching($term)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(SearchResult::fromDatabase(...));
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function serversMatching(string $term): Collection
    {
        return Server::query()
            ->matching($term)
            ->byName()
            ->limit(self::LIMIT)
            ->get()
            ->map(SearchResult::fromServer(...));
    }
}
