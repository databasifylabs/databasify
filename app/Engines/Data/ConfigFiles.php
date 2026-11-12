<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class ConfigFiles
{
    /**
     * @param  list<ConfigFile>  $files
     */
    public function __construct(
        public array $files,
        public string $manifest,
    ) {}
}
