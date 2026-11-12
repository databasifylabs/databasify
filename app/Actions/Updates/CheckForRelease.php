<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\DTOs\Updates\Release;
use App\Models\Setting;
use App\Services\Updates\ReleaseFeed;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class CheckForRelease
{
    public function __construct(private ReleaseFeed $feed) {}

    public function handle(): Release
    {
        try {
            $release = $this->feed->latest();
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['check' => 'Could not reach get.databasify.io to check for a new release. Try again later.']);
        }

        $this->store($release);

        return $release;
    }

    private function store(Release $release): void
    {
        Setting::query()
            ->updateOrCreate(['name' => 'latest_release'], ['value' => $release->toJson()])
            ->touch();
    }
}
