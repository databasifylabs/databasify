<?php

declare(strict_types=1);

namespace App\DTOs\Plugins;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final readonly class PluginSource
{
    public const string GITHUB = '#^(?:https://github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#';

    private const string OFFICIAL_OWNER = 'databasifylabs';

    public function __construct(
        public string $label,
        public ?string $archivePath = null,
        public bool $acceptsCommunityEngines = false,
    ) {}

    public static function fromUpload(UploadedFile $archive, bool $acceptsCommunityEngines): self
    {
        return new self(Str::limit($archive->getClientOriginalName(), 240).' (uploaded)', (string) $archive->getRealPath(), $acceptsCommunityEngines);
    }

    public function isOfficial(): bool
    {
        return $this->archivePath === null
            && preg_match(self::GITHUB, $this->label, $repository) === 1
            && strtolower($repository[1]) === self::OFFICIAL_OWNER;
    }
}
