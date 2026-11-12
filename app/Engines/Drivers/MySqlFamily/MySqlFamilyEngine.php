<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use InvalidArgumentException;

final readonly class MySqlFamilyEngine
{
    private function __construct(
        public string $id,
        public string $home,
        public string $client,
    ) {}

    public static function of(string $engine): self
    {
        return match ($engine) {
            'mysql' => new self('mysql', '/srv/mysql', 'mysql'),
            'mariadb' => new self('mariadb', '/srv/mariadb', 'mariadb'),
            default => throw new InvalidArgumentException("{$engine} is not a MySQL family engine."),
        };
    }

    public function adminCnf(): string
    {
        return "{$this->home}/conf/admin.cnf";
    }
}
