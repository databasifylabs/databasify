<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum PasswordEncryption: string
{
    case CachingSha2Password = 'caching_sha2_password';
    case MysqlNativePassword = 'mysql_native_password';
    case Ed25519 = 'ed25519';
    case Parsec = 'parsec';
    case ScramSha256 = 'SCRAM-SHA-256';
    case ScramSha1 = 'SCRAM-SHA-1';

    public function label(): string
    {
        return match ($this) {
            self::CachingSha2Password => 'Default - MySQL 8+',
            self::MysqlNativePassword => 'Legacy - MySQL 5.x',
            self::Ed25519 => 'Ed25519 - MariaDB',
            self::Parsec => 'PARSEC - MariaDB 11.6+',
            self::ScramSha256 => 'Default - SCRAM-SHA-256',
            self::ScramSha1 => 'Legacy - SCRAM-SHA-1',
        };
    }
}
