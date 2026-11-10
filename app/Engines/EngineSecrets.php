<?php

declare(strict_types=1);

namespace App\Engines;

final readonly class EngineSecrets
{
    public const string ADMIN = 'ENGINE_ADMIN';

    public const string REPLICATION = 'ENGINE_REPLICATION';

    public const string AGENT = 'ENGINE_AGENT';

    /**
     * @return array<string, string>
     */
    public static function generate(): array
    {
        $names = [self::ADMIN, self::REPLICATION, self::AGENT];

        return array_combine($names, array_map(fn (): string => new_password(), $names));
    }
}
