<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\MigrationStart;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\GoJson;
use App\Engines\Drivers\Support\ConfigView;

final readonly class SentinelFiles
{
    public const int DATA_PORT = 6379;

    public const int TLS_PORT = 25061;

    public const int SENTINEL_PORT = 26379;

    public const string SNAPSHOT_SCHEDULE = '3600 1';

    public static function serverConfOf(SentinelFamilyEngine $engine, NodeConfig $request, string $primary): string
    {
        $settings = $request->settings;
        unset($settings[SentinelSettings::PERSISTENCE]);
        ksort($settings, SORT_STRING);

        return ConfigView::render('engines.sentinel.files.server-conf', [
            'dataPort' => self::DATA_PORT,
            'tlsPort' => self::TLS_PORT,
            'home' => $engine->home(),
            'password' => self::quoted((string) $request->secrets->admin),
            'persistent' => self::persistent($request->settings),
            'snapshot' => self::SNAPSHOT_SCHEDULE,
            'directives' => array_map(self::directiveOf(...), array_keys($settings), $settings),
            'clustered' => $request->clustered,
            'ip' => $request->ip,
            'requiredReplicas' => self::requiredReplicasFor(count($request->members)),
            'readOnly' => $request->readOnly,
            'replicaOf' => $primary !== '' && $primary !== $request->ip ? $primary : null,
        ]);
    }

    public static function sentinelConfOf(SentinelFamilyEngine $engine, NodeConfig $request, string $primary): string
    {
        return ConfigView::render('engines.sentinel.files.sentinel-conf', [
            'sentinelPort' => self::SENTINEL_PORT,
            'dataPort' => self::DATA_PORT,
            'ip' => $request->ip,
            'home' => $engine->home(),
            'password' => self::quoted((string) $request->secrets->admin),
            'primary' => $primary === '' ? $request->ip : $primary,
            'quorum' => self::majorityOf(count($request->members)),
        ]);
    }

    public static function manifestOf(SentinelFamilyEngine $engine, NodeConfig $request): string
    {
        $server = [...self::process($engine, $engine->serverUnit(), $request->clustered ? '15s' : '3s', '1m0s', [$engine->home()."/conf/{$engine->id}.conf"]), 'enabled' => true];
        $processes = [$server];

        if ($request->clustered) {
            $watcher = self::process($engine, $engine->sentinelUnit(), '3s', '10s', [$engine->home().'/sentinel/sentinel.conf', '--sentinel']);
            $processes[] = [...array_slice($watcher, 0, 3), 'after' => [$engine->serverUnit()], ...array_slice($watcher, 3), 'enabled' => false];
        }

        return GoJson::indented(['processes' => $processes, 'schedules' => []]);
    }

    public static function shakeConfigOf(MigrationStart $request, string $dir, string $password): string
    {
        $source = $request->source;
        $reader = $request->continuous ? ['[sync_reader]', 'sync_rdb = true', 'sync_aof = true'] : ['[scan_reader]', 'scan = true', 'ksn = false', 'count = 100'];

        return implode("\n", [
            ...$reader,
            'cluster = false',
            'address = '.self::quoted(self::joinHostPort($source->host, $source->port)),
            'username = '.self::quoted($source->username),
            'password = '.self::quoted($source->password),
            'tls = '.($source->tls ? 'true' : 'false'),
            '',
            '[redis_writer]',
            'cluster = false',
            'address = '.self::quoted('127.0.0.1:'.self::DATA_PORT),
            'password = '.self::quoted(trim($password)),
            'tls = false',
            '',
            '[advanced]',
            'dir = '.self::quoted($dir),
            'status_port = 0',
            'log_file = "shake.log"',
            'log_level = "info"',
            'log_interval = 5',
            'rdb_restore_command_behavior = "rewrite"',
        ])."\n";
    }

    public static function directiveOf(string $key, string $value): string
    {
        if ($key === SentinelSettings::PUBSUB_LIMIT) {
            $limit = (int) $value;

            return sprintf('client-output-buffer-limit pubsub %dmb %dmb 60', $limit, intdiv($limit, 4));
        }

        return $value === '' ? "{$key} \"\"" : "{$key} {$value}";
    }

    public static function configKeyOf(string $key): string
    {
        return explode(' ', self::directiveOf($key, '0'), 2)[0];
    }

    /**
     * @param  array<string, string>  $settings
     */
    public static function persistent(array $settings): bool
    {
        return ($settings[SentinelSettings::PERSISTENCE] ?? null) !== 'no';
    }

    public static function quoted(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    public static function requiredReplicasFor(int $size): int
    {
        return $size >= 3 ? 1 : 0;
    }

    public static function majorityOf(int $size): int
    {
        return intdiv($size, 2) + 1;
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private static function process(SentinelFamilyEngine $engine, string $name, string $restart, string $stop, array $args): array
    {
        return [
            'name' => $name,
            'argv' => ['sh', '-c', 'umask 0027 && exec "$@"', 'private-umask', $engine->server, ...$args],
            'user' => $engine->id,
            'restart' => 'always',
            'restartSec' => $restart,
            'stopSignal' => 'TERM',
            'stopTimeout' => $stop,
        ];
    }

    private static function joinHostPort(string $host, int $port): string
    {
        return (str_contains($host, ':') ? "[{$host}]" : $host).":{$port}";
    }
}
