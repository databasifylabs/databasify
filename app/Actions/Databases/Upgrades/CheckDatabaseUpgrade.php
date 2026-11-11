<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use Illuminate\Support\Facades\Cache;

final readonly class CheckDatabaseUpgrade
{
    private const int REFUSAL_TTL_DAYS = 30;

    public function __construct(private ImageResolver $newest) {}

    public function handle(Database $database): ?EngineImage
    {
        try {
            $newest = $this->newest->newest($database, $database->version_line);
        } catch (ImageUnavailable $unavailable) {
            return $this->unlessRefused($unavailable, $database);
        }

        $image = $newest->replaces($database->image()) ? $newest : null;
        $database->recordAvailableImage($image);
        Cache::forget($this->refusalKeyOf($database));

        return $image;
    }

    private function unlessRefused(ImageUnavailable $unavailable, Database $database): null
    {
        $refused = $unavailable->refused ?? throw $unavailable;

        if (Cache::get($this->refusalKeyOf($database)) === $refused->digest) {
            return null;
        }

        Cache::put($this->refusalKeyOf($database), $refused->digest, now()->addDays(self::REFUSAL_TTL_DAYS));
        event(new DatabaseAlertRaised($database, "{$database->name} stays on {$database->engine()->label} {$database->image->release}", $unavailable->getMessage()));

        return null;
    }

    private function refusalKeyOf(Database $database): string
    {
        return "sdk-refused:{$database->id}";
    }
}
