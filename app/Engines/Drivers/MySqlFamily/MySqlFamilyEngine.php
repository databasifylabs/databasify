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
        public string $daemon,
    ) {}

    public static function of(string $engine): self
    {
        return match ($engine) {
            'mysql' => new self('mysql', '/srv/mysql', 'mysql', 'mysqld'),
            'mariadb' => new self('mariadb', '/srv/mariadb', 'mariadb', 'mariadbd'),
            default => throw new InvalidArgumentException("{$engine} is not a MySQL family engine."),
        };
    }

    public function adminCnf(): string
    {
        return "{$this->home}/conf/admin.cnf";
    }

    public function socket(): string
    {
        return "{$this->home}/run/{$this->daemon}.sock";
    }

    public function serverUnit(): string
    {
        return "{$this->id}-server";
    }

    /**
     * @return array{stream: string, ship: string, backup: string}
     */
    public function archiveUnits(): array
    {
        return ['stream' => "{$this->id}-binlog-stream", 'ship' => "{$this->id}-binlog-ship", 'backup' => "{$this->id}-backup"];
    }
}
