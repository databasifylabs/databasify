<?php

declare(strict_types=1);

namespace App\Engines;

use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\ServiceConnection;
use App\DTOs\Databases\VersionLine;
use App\DTOs\Migrations\SourceForm;
use App\Engines\Contracts\EngineDriver;
use App\Engines\Data\ServiceRecord;
use App\Exceptions\Databases\UnknownEngine;
use Illuminate\Support\Collection;

final readonly class EngineCatalog
{
    public const string CONFIG = 'engine.providers';

    /**
     * @return Collection<string, Engine>
     */
    public static function all(): Collection
    {
        return collect(array_keys(config()->array(self::CONFIG, [])))
            ->filter(fn (int|string $id): bool => is_string($id))
            ->mapWithKeys(fn (string $id): array => [$id => self::find($id)]);
    }

    public static function find(string $id): Engine
    {
        $entry = config(self::CONFIG.".{$id}");

        if (! is_array($entry)) {
            throw UnknownEngine::named($id);
        }

        $setting = fn (string $key): string => config()->string(self::CONFIG.".{$id}.{$key}", '');
        $flag = fn (string $key): bool => config()->boolean(self::CONFIG.".{$id}.{$key}", false);
        $optional = fn (string $key): ?string => is_string($entry[$key] ?? null) ? $entry[$key] : null;
        $icon = $setting('icon');
        $defaultLine = $setting('default_line');
        $environment = is_array($entry['environment'] ?? null) ? $entry['environment'] : [];

        return new Engine(
            id: $id,
            label: $setting('label') ?: $id,
            icon: $icon === '' ? null : asset($icon),
            category: $setting('category'),
            imageRepository: $setting('image') ?: self::defaultRepositoryOf($id),
            lines: self::linesOf($entry['lines'] ?? [], $defaultLine),
            defaultLine: $defaultLine,
            failoverHint: $setting('failover_hint'),
            serviceRecord: self::serviceRecordOf($entry['service_record'] ?? null),
            publicCertificate: $flag('public_certificate'),
            persistenceChoice: $flag('persistence_choice'),
            migrationSource: self::migrationSourceOf($entry['migration_source'] ?? null),
            connection: self::connectionOf($entry['connection'] ?? null) ?? ConnectionProfile::fromArray([]),
            publicConnection: self::connectionOf($entry['public_connection'] ?? null),
            serviceConnection: self::serviceConnectionOf($entry['service_connection'] ?? null),
            laravelConnection: is_string($environment['connection'] ?? null) ? $environment['connection'] : null,
            environmentExtra: self::stringMapOf($environment['extra'] ?? []),
            readOnlyNodesVote: $flag('read_only_nodes_vote'),
            plugin: $optional('plugin'),
            author: $optional('author'),
            driver: self::driverOf($optional('driver')),
        );
    }

    /**
     * @return class-string<EngineDriver>|null
     */
    private static function driverOf(?string $class): ?string
    {
        return $class !== null && is_a($class, EngineDriver::class, true) ? $class : null;
    }

    private static function defaultRepositoryOf(string $id): string
    {
        return config()->string('databasify.registry')."/databasify-{$id}";
    }

    /**
     * @return list<VersionLine>
     */
    private static function linesOf(mixed $lines, string $defaultLine): array
    {
        return array_values(collect(is_array($lines) ? $lines : [])
            ->filter(fn (mixed $line): bool => is_array($line) && is_string($line['value'] ?? null) && is_string($line['label'] ?? null))
            ->map(fn (array $line): VersionLine => new VersionLine($line['value'], $line['label'], $line['value'] === $defaultLine, ($line['recovery'] ?? true) !== false))
            ->all());
    }

    /**
     * @return array<string, string>
     */
    private static function stringMapOf(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_filter($values, fn (mixed $value, int|string $key): bool => is_string($value) && is_string($key), ARRAY_FILTER_USE_BOTH);
    }

    private static function connectionOf(mixed $connection): ?ConnectionProfile
    {
        return is_array($connection) ? ConnectionProfile::fromArray($connection) : null;
    }

    private static function serviceConnectionOf(mixed $connection): ?ServiceConnection
    {
        return is_array($connection) ? ServiceConnection::fromArray($connection) : null;
    }

    private static function migrationSourceOf(mixed $source): ?SourceForm
    {
        if (! is_array($source) || ! is_int($source['default_port'] ?? null)) {
            return null;
        }

        return new SourceForm($source['default_port'], ($source['credentials_required'] ?? true) === true, ($source['selects_databases'] ?? true) === true);
    }

    private static function serviceRecordOf(mixed $record): ?ServiceRecord
    {
        if (! is_array($record) || ! is_string($record['prefix'] ?? null) || ! is_string($record['options'] ?? null)) {
            return null;
        }

        return new ServiceRecord($record['prefix'], $record['options']);
    }
}
