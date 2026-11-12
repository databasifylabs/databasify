<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Drivers\GoJson;
use App\Engines\Requests\MigrationStart;
use App\Engines\Requests\NodeConfigure;

final readonly class SentinelFiles
{
    public const int DATA_PORT = 6379;

    public const int TLS_PORT = 25061;

    public const int SENTINEL_PORT = 26379;

    public const string SNAPSHOT_SCHEDULE = '3600 1';

    public static function serverConfOf(SentinelFamilyEngine $engine, NodeConfigure $request, string $primary): string
    {
        $home = $engine->home();
        $password = self::quoted((string) $request->secrets->admin);
        $lines = [
            'port '.self::DATA_PORT, 'bind * -::*', 'protected-mode yes', 'tcp-backlog 511', 'tcp-keepalive 300', 'daemonize no', 'loglevel notice', 'logfile ""',
            "dir {$home}/data", 'tls-port '.self::TLS_PORT, "tls-cert-file {$home}/tls/server.pem", "tls-key-file {$home}/tls/server.pem", "tls-ca-cert-file {$home}/tls/ca.crt",
            'tls-auth-clients no', 'tls-protocols "TLSv1.2 TLSv1.3"', "requirepass {$password}", "masterauth {$password}", 'maxmemory-clients 10%',
            'lazyfree-lazy-expire yes', 'lazyfree-lazy-server-del yes', 'lazyfree-lazy-user-del yes', 'lazyfree-lazy-user-flush yes', 'activedefrag yes', 'latency-monitor-threshold 100',
            ...self::persistenceLines(self::persistent($request->settings)),
        ];
        $settings = $request->settings;
        ksort($settings, SORT_STRING);

        foreach ($settings as $key => $value) {
            if ($key !== SentinelSettings::PERSISTENCE) {
                $lines[] = self::directiveOf($key, $value);
            }
        }

        if ($request->clustered) {
            array_push($lines, "replica-announce-ip {$request->ip}", 'min-replicas-to-write '.self::requiredReplicasFor(count($request->members)), 'min-replicas-max-lag 10');

            if ($request->readOnly) {
                $lines[] = 'replica-priority 0';
            }

            if ($primary !== '' && $primary !== $request->ip) {
                $lines[] = "replicaof {$primary} ".self::DATA_PORT;
            }
        }

        return implode("\n", $lines)."\n";
    }

    public static function sentinelConfOf(SentinelFamilyEngine $engine, NodeConfigure $request, string $primary): string
    {
        $password = self::quoted((string) $request->secrets->admin);
        $primary = $primary === '' ? $request->ip : $primary;

        return implode("\n", [
            'port '.self::SENTINEL_PORT, "bind {$request->ip} 127.0.0.1", 'daemonize no', 'dir '.$engine->home().'/sentinel', "requirepass {$password}",
            "sentinel announce-ip {$request->ip}", 'sentinel announce-port '.self::SENTINEL_PORT, "sentinel sentinel-pass {$password}",
            sprintf('sentinel monitor main %s %d %d', $primary, self::DATA_PORT, self::majorityOf(count($request->members))),
            "sentinel auth-pass main {$password}", 'sentinel down-after-milliseconds main 5000', 'sentinel failover-timeout main 60000', 'sentinel parallel-syncs main 1',
        ])."\n";
    }

    public static function manifestOf(SentinelFamilyEngine $engine, NodeConfigure $request): string
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
     * @return list<string>
     */
    private static function persistenceLines(bool $persistent): array
    {
        if (! $persistent) {
            return ['save ""', 'appendonly no'];
        }

        return ['save '.self::SNAPSHOT_SCHEDULE, 'stop-writes-on-bgsave-error yes', 'dbfilename dump.rdb', 'appendonly yes', 'appendfilename "appendonly.aof"', 'appenddirname "appendonlydir"', 'appendfsync everysec', 'aof-use-rdb-preamble yes'];
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
