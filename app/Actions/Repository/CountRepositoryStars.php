<?php

declare(strict_types=1);

namespace App\Actions\Repository;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class CountRepositoryStars
{
    private const string CACHE_KEY = 'repository-stars';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 3;

    public function handle(): ?int
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return is_int($cached) ? $cached : null;
        }

        $stars = $this->fetch();
        Cache::put(self::CACHE_KEY, $stars ?? false, $stars === null ? now()->addMinutes(10) : now()->addHour());

        return $stars;
    }

    private function fetch(): ?int
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS)->acceptJson()->get('https://api.github.com/repos/'.config()->string('databasify.repository'));
        } catch (ConnectionException) {
            return null;
        }

        $stars = $response->successful() ? $response->json('stargazers_count') : null;

        return is_int($stars) ? $stars : null;
    }
}
