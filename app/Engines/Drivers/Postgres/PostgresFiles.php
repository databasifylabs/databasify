<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Secrets;
use App\Engines\Data\Storage;
use App\Exceptions\Engines\InvalidEngineRequest;
use Illuminate\Contracts\View\Factory;
use LogicException;

final readonly class PostgresFiles
{
    public const string CONF = '/srv/postgres/conf';

    public const string CLIENT_PORT = '25060';

    private const string POOL_PORT = '25061';

    private const string ETCD_CLIENT_PORT = '2379';

    private const string ETCD_PEER_PORT = '2380';

    private const array CLUSTER_WIDE_KEYS = ['max_connections', 'max_locks_per_transaction', 'max_worker_processes', 'max_prepared_transactions', 'max_wal_senders', 'max_replication_slots', 'track_commit_timestamp', 'wal_level'];

    private const array HBA_RULES = [
        'local all postgres peer',
        'local all all scram-sha-256',
        'host all all 127.0.0.1/32 scram-sha-256',
        'host all all ::1/128 scram-sha-256',
        'host replication replicator 127.0.0.1/32 scram-sha-256',
        'host replication replicator ::1/128 scram-sha-256',
        'hostssl replication replicator 0.0.0.0/0 scram-sha-256',
        'hostssl all all 0.0.0.0/0 scram-sha-256',
    ];

    private const array REPLICATION_PARAMETERS = [
        'wal_level' => 'replica', 'hot_standby' => 'on', 'wal_log_hints' => 'on', 'max_wal_senders' => '20',
        'max_replication_slots' => '20', 'wal_keep_size' => '1GB', 'password_encryption' => 'scram-sha-256',
        'shared_preload_libraries' => 'pg_stat_statements', 'pg_stat_statements.track' => 'top', 'compute_query_id' => 'auto',
    ];

    private const array LOCAL_PARAMETERS = [
        'ssl' => 'on', 'ssl_cert_file' => '/srv/postgres/tls/server.crt', 'ssl_key_file' => '/srv/postgres/tls/server.key',
        'ssl_min_protocol_version' => 'TLSv1.2', 'unix_socket_directories' => '/srv/postgres/run',
        'log_destination' => 'stderr', 'logging_collector' => 'off',
    ];

    private const array ARCHIVE_PARAMETERS = [
        'archive_mode' => 'on', 'archive_command' => 'pgbackrest --config=/srv/postgres/conf/pgbackrest.conf --stanza=main archive-push %p', 'archive_timeout' => '60',
    ];

    public function __construct(private Factory $views) {}

    public function of(NodeConfig $config, string $line, string $defaultPoolSize): ConfigFiles
    {
        $data = self::viewDataOf($config, $line, $defaultPoolSize);
        $files = [];

        foreach (self::modesOf($config) as $name => $mode) {
            $files[] = new ConfigFile(self::CONF."/{$name}", $mode, $this->render($name, $data));
        }

        return new ConfigFiles($files, PostgresManifest::of($config, $line));
    }

    /**
     * @param  list<array{string, string}>  $repository
     */
    public function backrestConfig(array $repository, ?int $retentionDays): string
    {
        return $this->render('pgbackrest.conf', ['repository' => $repository, 'retentionDays' => $retentionDays, 'port' => self::CLIENT_PORT]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function viewDataOf(NodeConfig $config, string $line, string $defaultPoolSize): array
    {
        [$clusterWide, $local] = self::parametersOf($config);
        [$initialCluster, $clusterState] = self::initialClusterOf($config);
        $archive = $config->archive;

        return [
            'config' => $config,
            'line' => $line,
            'port' => self::CLIENT_PORT,
            'poolPort' => self::POOL_PORT,
            'clusterWide' => $clusterWide,
            'local' => $local,
            'passwords' => self::passwordsOf($config->secrets),
            'hbaRules' => self::HBA_RULES,
            'etcdHosts' => self::etcdHostsOf($config->members),
            'initialCluster' => $initialCluster,
            'clusterState' => $clusterState,
            'readOnly' => $config->readOnly ? 'true' : 'false',
            'maxClients' => max(5000, (int) ($config->settings['max_connections'] ?? 100) * 10),
            'defaultPoolSize' => $defaultPoolSize,
            'repository' => $archive === null ? [] : self::repositoryOf($archive->storage),
            'retentionDays' => $archive?->retentionDays,
            'q' => self::quoted(...),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $name, array $data): string
    {
        $view = 'engines.postgres.files.'.str_replace('.', '-', $name);

        if (! $this->views->exists($view)) {
            throw new LogicException("PostgreSQL has no {$name} template.");
        }

        return $this->views->make($view, $data)->render();
    }

    /**
     * @return array<string, int>
     */
    private static function modesOf(NodeConfig $request): array
    {
        $modes = ['pgbouncer.ini' => 0o640, 'pgbouncer-users.txt' => 0o600];

        $modes += match (true) {
            ! $request->clustered => ['postgresql.conf' => 0o640, 'pg_hba.conf' => 0o640],
            $request->readOnly => ['patroni.yml' => 0o600],
            default => ['patroni.yml' => 0o600, 'etcd.env' => 0o640, 'dcs.yml' => 0o640],
        };

        if ($request->archive !== null) {
            $modes['pgbackrest.conf'] = 0o600;
        }

        ksort($modes, SORT_STRING);

        return $modes;
    }

    /**
     * @return array{admin: string, replication: string, agent: string, pooler: string}
     */
    public static function passwordsOf(Secrets $secrets): array
    {
        if ($secrets->admin === null || $secrets->replication === null || $secrets->agent === null) {
            throw new InvalidEngineRequest('invalid_request', 'PostgreSQL needs the admin, replication and agent secrets');
        }

        return ['admin' => $secrets->admin, 'replication' => $secrets->replication, 'agent' => $secrets->agent, 'pooler' => hash_hmac('sha256', 'pgbouncer', $secrets->agent)];
    }

    /**
     * @return array{array<string, string>, array<string, string>}
     */
    private static function parametersOf(NodeConfig $request): array
    {
        $slots = (string) max(20, count($request->members) * 2 + 4);
        $clusterWide = [...self::REPLICATION_PARAMETERS, 'max_wal_senders' => $slots, 'max_replication_slots' => $slots];
        $local = $request->archive === null ? self::LOCAL_PARAMETERS : [...self::LOCAL_PARAMETERS, ...self::ARCHIVE_PARAMETERS];

        foreach ($request->settings as $key => $value) {
            if (in_array($key, self::CLUSTER_WIDE_KEYS, true)) {
                $clusterWide[$key] = $value;
            } else {
                $local[$key] = $value;
            }
        }

        ksort($clusterWide, SORT_STRING);
        ksort($local, SORT_STRING);

        return [$clusterWide, $local];
    }

    public static function quoted(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  list<Member>  $members
     */
    public static function etcdHostsOf(array $members): string
    {
        return implode(',', array_map(fn (Member $member): string => $member->ip.':'.self::ETCD_CLIENT_PORT, $members));
    }

    /**
     * @return array{string, string}
     */
    public static function initialClusterOf(NodeConfig $request): array
    {
        $own = 0;

        foreach ($request->members as $member) {
            if ($member->ip === $request->ip) {
                $own = $member->position;
            }
        }

        $peers = [];

        foreach ($request->members as $member) {
            if ($member->voting && $member->position <= $own) {
                $peers[] = "{$member->ip}=http://{$member->ip}:".self::ETCD_PEER_PORT;
            }
        }

        return [implode(',', $peers), count($peers) === 1 ? 'new' : 'existing'];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function repositoryOf(Storage $storage): array
    {
        $host = str_starts_with($storage->endpoint, 'https://') ? substr($storage->endpoint, 8) : $storage->endpoint;
        $options = [['repo1-type', 's3']];

        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $match) === 1 || preg_match('/^([^:\[\]]+):(\d+)$/', $host, $match) === 1) {
            $host = $match[1];
            $options[] = ['repo1-storage-port', $match[2]];
        }

        $options[] = ['repo1-s3-endpoint', $host];
        $options[] = ['repo1-s3-bucket', $storage->bucket];
        $options[] = ['repo1-s3-region', $storage->region];
        $options[] = ['repo1-s3-key', $storage->accessKey];
        $options[] = ['repo1-s3-key-secret', $storage->secretKey];
        $options[] = ['repo1-s3-uri-style', $storage->pathStyle ? 'path' : 'host'];
        $options[] = ['repo1-path', '/'.trim($storage->path, '/')];

        return $options;
    }
}
