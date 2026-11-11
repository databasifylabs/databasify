<?php

declare(strict_types=1);

namespace App\DTOs\Plugins;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final readonly class PluginSource
{
    public function __construct(
        public string $label,
        public ?string $archivePath = null,
        public bool $acceptsCommunityEngines = false,
    ) {}

    public static function fromUpload(UploadedFile $archive, bool $acceptsCommunityEngines): self
    {
        return new self(Str::limit($archive->getClientOriginalName(), 240).' (uploaded)', (string) $archive->getRealPath(), $acceptsCommunityEngines);
    }
}
