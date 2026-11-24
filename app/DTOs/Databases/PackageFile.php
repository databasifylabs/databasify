<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;
use Illuminate\Support\Str;

final readonly class PackageFile
{
    public function __construct(
        public string $sha256,
        public string $fileName,
        public string $architecture,
        public string $package,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        $fileName = $payload->string('fileName');

        return new self($payload->string('sha256'), $fileName, $payload->string('architecture'), $payload->nullableString('package') ?? Str::before($fileName, '_'));
    }

    public static function fromHost(Payload $payload): self
    {
        return new self($payload->matching('sha256', '/^[0-9a-f]{64}$/'), $payload->matching('fileName', '/^[A-Za-z0-9][A-Za-z0-9._+~-]*$/'), $payload->string('architecture'), $payload->string('package'));
    }

    public function hostName(): string
    {
        return "{$this->sha256}.".pathinfo($this->fileName, PATHINFO_EXTENSION);
    }

    /**
     * @return array{sha256: string, fileName: string, architecture: string, package: string}
     */
    public function toArray(): array
    {
        return ['sha256' => $this->sha256, 'fileName' => $this->fileName, 'architecture' => $this->architecture, 'package' => $this->package];
    }
}
