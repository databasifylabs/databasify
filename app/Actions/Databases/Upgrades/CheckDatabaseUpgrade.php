<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use App\Support\Engines\SdkRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final readonly class CheckDatabaseUpgrade
{
    private const int REFUSAL_TTL_DAYS = 30;

    public function __construct(private ImageResolver $resolver) {}

    public function handle(Database $database): ?EngineImage
    {
        try {
            $images = $this->resolver->imagesOf($database, $database->version_line);
        } catch (ImageUnavailable $unavailable) {
            throw ValidationException::withMessages(['version' => $unavailable->getMessage()]);
        }

        $newest = $images->newest;
        $refusalKey = "sdk-refused:{$database->id}";

        if (SdkRange::supports($newest)) {
            Cache::forget($refusalKey);
        } elseif (Cache::get($refusalKey) !== $newest->digest) {
            Cache::put($refusalKey, $newest->digest, now()->addDays(self::REFUSAL_TTL_DAYS));
            $label = $database->engine()->label;
            event(new DatabaseAlertRaised($database, "{$database->name} stays on {$label} {$database->image->release}", SdkRange::refusalFor($label, $newest)));
        }

        if ($images->supported === null) {
            return null;
        }

        $image = $images->supported->replaces($database->image()) ? $images->supported : null;
        $database->recordAvailableImage($image);

        return $image;
    }
}
