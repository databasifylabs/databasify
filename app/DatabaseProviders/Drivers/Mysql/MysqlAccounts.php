<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mysql;

use App\DatabaseProviders\Groups\EngineAccounts;
use App\Enums\Databases\PasswordEncryption;

final readonly class MysqlAccounts extends EngineAccounts
{
    private const string NATIVE_PASSWORDS_REMOVED_IN = '9.0';

    public function reservedUsers(): array
    {
        return ['root', 'mysql', 'admin', 'router', 'clusteradmin', 'mysql_router', 'mysql_innodb_cluster'];
    }

    public function reservedDatabases(): array
    {
        return ['mysql', 'sys', 'information_schema', 'performance_schema', 'mysql_innodb_cluster_metadata'];
    }

    public function passwordEncryption(): PasswordEncryption
    {
        return PasswordEncryption::Default;
    }

    public function passwordEncryptions(): array
    {
        return $this->hasNativePasswords() ? PasswordEncryption::cases() : [PasswordEncryption::Default];
    }

    private function hasNativePasswords(): bool
    {
        return version_compare($this->engine->versionLine(), self::NATIVE_PASSWORDS_REMOVED_IN, '<');
    }
}
