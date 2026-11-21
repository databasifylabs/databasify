<?php

declare(strict_types=1);

namespace App\DTOs\Api;

use App\Models\Plugin;
use Illuminate\Database\Eloquent\Collection;

final readonly class SystemInfo
{
    /**
     * @param  Collection<int, Plugin>  $plugins
     */
    public function __construct(
        public string $version,
        public ?string $latestVersion,
        public bool $updateAvailable,
        public Collection $plugins,
    ) {}
}
