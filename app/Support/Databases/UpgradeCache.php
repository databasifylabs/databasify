<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\DTOs\Databases\ExtensionPackage;
use App\Models\Database;
use Illuminate\Support\Facades\Cache;

final readonly class UpgradeCache
{
    private const int REMEMBER_DAYS = 30;

    public function rememberSdkRefusal(Database $database, string $imageDigest): bool
    {
        if (Cache::get($this->sdkRefusalKeyOf($database)) === $imageDigest) {
            return false;
        }

        Cache::put($this->sdkRefusalKeyOf($database), $imageDigest, now()->addDays(self::REMEMBER_DAYS));

        return true;
    }

    public function forgetSdkRefusal(Database $database): void
    {
        Cache::forget($this->sdkRefusalKeyOf($database));
    }

    public function recordPreparationFailure(Database $database, string $imageDigest): void
    {
        Cache::put($this->preparationFailureKeyOf($database, $imageDigest), true, now()->addDays(self::REMEMBER_DAYS));
    }

    public function preparationFailed(Database $database, string $imageDigest): bool
    {
        return Cache::has($this->preparationFailureKeyOf($database, $imageDigest));
    }

    private function sdkRefusalKeyOf(Database $database): string
    {
        return "sdk-refused:{$database->id}";
    }

    private function preparationFailureKeyOf(Database $database, string $imageDigest): string
    {
        return "packages-unprepared:{$database->id}:{$imageDigest}:".implode(',', ExtensionPackage::fingerprintsOf($database->settings->packages));
    }
}
