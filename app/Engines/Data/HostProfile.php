<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class HostProfile
{
    /**
     * @param  array<string, string>  $settings
     * @param  array<string, int>  $floors
     */
    public function __construct(
        public array $settings,
        public array $floors = [],
        public bool $disableTransparentHugepages = false,
    ) {}

    /**
     * @return array{settings: object, floors: object, disableTransparentHugepages: bool}
     */
    public function toPayload(): array
    {
        return ['settings' => (object) $this->settings, 'floors' => (object) $this->floors, 'disableTransparentHugepages' => $this->disableTransparentHugepages];
    }
}
