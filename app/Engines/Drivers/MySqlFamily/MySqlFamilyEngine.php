<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Drivers\MariaDb\MariaDbFiles;
use App\Engines\Drivers\MySql\MySqlFiles;
use InvalidArgumentException;

final readonly class MySqlFamilyEngine
{
    /**
     * @param  list<string>  $reservedUsers
     * @param  list<string>  $reservedDatabases
     */
    private function __construct(
        public string $id,
        public string $label,
        public string $home,
        public string $client,
        public string $daemon,
        public string $defaultEncryption,
        public array $reservedUsers,
        public array $reservedDatabases,
    ) {}

    public static function of(string $engine): self
    {
        return match ($engine) {
            'mysql' => new self('mysql', 'MySQL', '/srv/mysql', 'mysql', 'mysqld', 'caching_sha2_password',
                ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'databasify_repl', 'databasify_agent'],
                ['mysql', 'sys', 'performance_schema', 'information_schema']),
            'mariadb' => new self('mariadb', 'MariaDB', '/srv/mariadb', 'mariadb', 'mariadbd', 'mysql_native_password',
                ['root', 'mysql', 'mariadb.sys', 'databasify_sst', 'databasify_repl', 'databasify_agent'],
                ['mysql', 'sys', 'performance_schema', 'information_schema', 'databasify']),
            default => throw new InvalidArgumentException("{$engine} is not a MySQL family engine."),
        };
    }

    /**
     * @return list<string>
     */
    public function encryptionsOn(string $line): array
    {
        return match (true) {
            $this->id === 'mysql' => MySqlFiles::nativePasswords($line) ? ['caching_sha2_password', 'mysql_native_password'] : ['caching_sha2_password'],
            MariaDbFiles::parsecOffered($line) => ['mysql_native_password', 'ed25519', 'parsec'],
            default => ['mysql_native_password', 'ed25519'],
        };
    }

    /**
     * @return array{string, string}
     */
    public function identifiedWith(string $encryption): array
    {
        return match (true) {
            $this->id === 'mysql' => ["IDENTIFIED WITH {$encryption} BY ", ''],
            in_array($encryption, ['ed25519', 'parsec'], true) => ["IDENTIFIED VIA {$encryption} USING PASSWORD(", ')'],
            default => ['IDENTIFIED BY ', ''],
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
