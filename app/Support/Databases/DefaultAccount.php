<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\DTOs\Databases\DatabaseEndpoints;
use App\Models\Database;

final readonly class DefaultAccount
{
    public const string USERNAME = 'databasify';

    public const string DATABASE = 'defaultdb';

    /**
     * @return array<string, string>
     */
    public static function secrets(): array
    {
        return [
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => self::USERNAME,
            'DB_PASSWORD' => new_password(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function environment(Database $database, DatabaseEndpoints $endpoints, string $connection): array
    {
        $settings = $database->settings;

        return [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $endpoints->public->host,
            'DB_PORT' => (string) $endpoints->public->port,
            'DB_DATABASE' => $settings->defaultSchema(),
            'DB_USERNAME' => $settings->defaultUsername(),
            'DB_PASSWORD' => $settings->defaultPassword(),
        ];
    }
}
