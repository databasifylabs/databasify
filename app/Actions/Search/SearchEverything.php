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
        $databases = Database::query()->withRegisteredEngine()->matching($term)->orderBy('name')->limit(self::LIMIT)->get()->map(SearchResult::fromDatabase(...));
        $servers = Server::query()->matching($term)->byName()->limit(self::LIMIT)->get()->map(SearchResult::fromServer(...));

        return $databases->toBase()->concat($servers);
    }
}
