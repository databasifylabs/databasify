<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class NodeFile
{
    public const string ENGINE_ON_HOST = '"$engine_uid" "$engine_gid"';

    private const string ROOT_ON_HOST = '0 0';

    public function __construct(
        public string $path,
        public string $content,
        public string $mode = '600',
        public string $owner = 'root',
        public bool $keepExisting = false,
    ) {}

    public static function secret(string $path, string $content, string $owner): self
    {
        return new self($path, $content, '600', $owner);
    }

    public static function config(string $path, string $content, string $owner = 'root'): self
    {
        return new self($path, $content, '640', $owner);
    }

    public static function script(string $path, string $content, string $owner = 'root'): self
    {
        return new self($path, "#!/bin/bash\n{$content}", '700', $owner);
    }

    public function hostOwner(): string
    {
        return $this->owner === 'root' ? self::ROOT_ON_HOST : self::ENGINE_ON_HOST;
    }

    public function keptIfPresent(): self
    {
        return clone ($this, ['keepExisting' => true]);
    }
}
