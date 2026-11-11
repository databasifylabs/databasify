<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use App\Support\Engines\SdkRange;
use Illuminate\Support\Facades\Cache;

final readonly class CheckDatabaseUpgrade
{
    private const int REFUSAL_TTL_DAYS = 30;

    public function __construct(private ImageResolver $resolver) {}

    public function handle(Database $database): ?EngineImage
    {
        $images = $this->resolver->imagesOf($database, $database->version_line);
        $this->warnUnlessSupported($images->newest, $database);

        if ($images->supported === null) {
            return null;
        }

        $image = $images->supported->replaces($database->image()) ? $images->supported : null;
        $database->recordAvailableImage($image);

        return $image;
    }

    private function warnUnlessSupported(EngineImage $newest, Database $database): void
    {
        if (SdkRange::allows($newest->sdk)) {
            Cache::forget($this->refusalKeyOf($database));

            return;
        }

        if (Cache::get($this->refusalKeyOf($database)) === $newest->digest) {
            return;
        }

        Cache::put($this->refusalKeyOf($database), $newest->digest, now()->addDays(self::REFUSAL_TTL_DAYS));
        $label = $database->engine()->label;
        event(new DatabaseAlertRaised($database, "{$database->name} stays on {$label} {$database->image->release}", SdkRange::refusalOf("{$label} {$newest->release}", $newest->sdk)));
    }

    private function refusalKeyOf(Database $database): string
    {
        return "sdk-refused:{$database->id}";
    }
}
