<?php

declare(strict_types=1);

namespace App\DTOs\Updates;

use App\Services\Http\Payload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use UnexpectedValueException;

final readonly class Release
{
    private const string VERSION = '/^\d+\.\d+\.\d+$/';

    public function __construct(
        public string $version,
        public CarbonImmutable $publishedAt,
        public string $url,
        public string $notes,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        $version = $payload->string('version');

        if (preg_match(self::VERSION, $version) !== 1) {
            throw new UnexpectedValueException("The release feed has no valid version: [{$version}].");
        }

        return new self(
            $version,
            CarbonImmutable::parse($payload->string('published_at')),
            $payload->string('url'),
            $payload->string('notes'),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromPayload(Payload::of(json_decode($json, true)));
    }

    public function toJson(): string
    {
        return json_encode([
            'version' => $this->version,
            'published_at' => $this->publishedAt->toIso8601ZuluString(),
            'url' => $this->url,
            'notes' => $this->notes,
        ], JSON_THROW_ON_ERROR);
    }

    public function notesHtml(): string
    {
        return Str::markdown($this->notes, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function isNewerThan(string $version): bool
    {
        return version_compare($this->version, $version) > 0;
    }
}
