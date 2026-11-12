<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Requests\NodeConfigure;
use LogicException;

final readonly class PostgresFileView
{
    /**
     * @param  array<string, string>  $clusterWide
     * @param  array<string, string>  $local
     * @param  array{admin: string, replication: string, agent: string, pooler: string}  $passwords
     */
    public function __construct(
        private NodeConfigure $request,
        private string $line,
        private array $clusterWide,
        private array $local,
        private array $passwords,
        private string $defaultPoolSize,
    ) {}

    public function render(string $name): string
    {
        return match ($name) {
            'postgresql.conf' => $this->serverConf(),
            'pg_hba.conf' => $this->lines(PostgresFiles::hbaRules(), '%s'),
            'patroni.yml' => $this->patroni(),
            'etcd.env' => $this->etcd(),
            'dcs.yml' => $this->dcs(),
            'pgbouncer.ini' => $this->pgbouncer(),
            'pgbouncer-users.txt' => "\"pgbouncer\" \"{$this->passwords['pooler']}\"\n",
            'pgbackrest.conf' => $this->pgbackrest(),
            default => throw new LogicException("PostgreSQL has no {$name} template."),
        };
    }

    private function serverConf(): string
    {
        return "listen_addresses = '*'\nport = '".PostgresFiles::CLIENT_PORT."'\nhba_file = '/srv/postgres/conf/pg_hba.conf'\n"
            .$this->options($this->clusterWide, '%s = %s').$this->options($this->local, '%s = %s');
    }

    private function patroni(): string
    {
        $ip = $this->request->ip;
        $port = PostgresFiles::CLIENT_PORT;
        $readOnly = $this->request->readOnly ? 'true' : 'false';
        $q = PostgresFiles::quoted(...);

        return "scope: 'main'\nname: '{$ip}'\nrestapi:\n  listen: '0.0.0.0:8008'\n  connect_address: '{$ip}:8008'\n  authentication:\n    username: patroni\n    password: {$q($this->passwords['agent'])}\n"
            ."etcd3:\n  hosts: '".PostgresFiles::etcdHostsOf($this->request->members)."'\n"
            ."bootstrap:\n  dcs:\n    ttl: 30\n    loop_wait: 10\n    retry_timeout: 10\n    maximum_lag_on_failover: 1048576\n    failsafe_mode: true\n    postgresql:\n      use_pg_rewind: true\n      use_slots: true\n      parameters:\n"
            .$this->options($this->clusterWide, '        %s: %s')
            ."  initdb:\n    - encoding: UTF8\n    - data-checksums\n"
            ."postgresql:\n  listen: '*:{$port}'\n  connect_address: '{$ip}:{$port}'\n  data_dir: '/srv/postgres/data'\n  bin_dir: '/usr/lib/postgresql/{$this->line}/bin'\n  pgpass: '/srv/postgres/conf/pgpass'\n  use_unix_socket: true\n  use_unix_socket_repl: false\n  remove_data_directory_on_rewind_failure: true\n  remove_data_directory_on_diverged_timelines: true\n"
            ."  authentication:\n    superuser:\n      username: postgres\n      password: {$q($this->passwords['admin'])}\n    replication:\n      username: replicator\n      password: {$q($this->passwords['replication'])}\n    rewind:\n      username: postgres\n      password: {$q($this->passwords['admin'])}\n"
            ."  parameters:\n".$this->options($this->local, '    %s: %s')
            ."  pg_hba:\n".$this->lines(PostgresFiles::hbaRules(), "    - '%s'")
            ."tags:\n  nofailover: {$readOnly}\n  noloadbalance: {$readOnly}\n  nosync: {$readOnly}\n  clonefrom: {$readOnly}\n";
    }

    private function etcd(): string
    {
        $ip = $this->request->ip;
        [$initialCluster, $state] = PostgresFiles::initialClusterOf($this->request);

        return "ETCD_NAME={$ip}\nETCD_DATA_DIR=/srv/postgres/etcd\nETCD_LISTEN_CLIENT_URLS=http://{$ip}:2379,http://127.0.0.1:2379\nETCD_ADVERTISE_CLIENT_URLS=http://{$ip}:2379\nETCD_LISTEN_PEER_URLS=http://{$ip}:2380\nETCD_INITIAL_ADVERTISE_PEER_URLS=http://{$ip}:2380\n"
            ."ETCD_INITIAL_CLUSTER={$initialCluster}\nETCD_INITIAL_CLUSTER_STATE={$state}\nETCD_INITIAL_CLUSTER_TOKEN=databasify-{$this->request->databaseId}\n"
            ."ETCD_MAX_LEARNERS=7\nETCD_AUTO_COMPACTION_MODE=periodic\nETCD_AUTO_COMPACTION_RETENTION=1h\nETCD_QUOTA_BACKEND_BYTES=2147483648\n";
    }

    private function dcs(): string
    {
        return "ttl: 30\nloop_wait: 10\nretry_timeout: 10\nmaximum_lag_on_failover: 1048576\nfailsafe_mode: true\npostgresql:\n  use_pg_rewind: true\n  use_slots: true\n  parameters:\n"
            .$this->options($this->clusterWide, '    %s: %s');
    }

    private function pgbouncer(): string
    {
        $connections = (int) ($this->request->settings['max_connections'] ?? 100);
        $clients = max(5000, $connections * 10);

        return "[databases]\npostgres = host=127.0.0.1 port=".PostgresFiles::CLIENT_PORT." dbname=postgres pool_size=2\n%include /srv/postgres/conf/pgbouncer-pools.ini\n\n"
            ."[pgbouncer]\nlisten_addr = *\nlisten_port = ".PostgresFiles::poolPort()."\nunix_socket_dir =\nauth_type = scram-sha-256\nauth_file = /srv/postgres/conf/pgbouncer-users.txt\nauth_user = pgbouncer\nauth_dbname = postgres\n"
            .'auth_query = SELECT username, password FROM pgbouncer.get_auth($1)'."\n"
            ."client_tls_sslmode = require\nclient_tls_cert_file = /srv/postgres/tls/server.crt\nclient_tls_key_file = /srv/postgres/tls/server.key\nserver_tls_sslmode = require\n"
            ."max_client_conn = {$clients}\ndefault_pool_size = {$this->defaultPoolSize}\nignore_startup_parameters = extra_float_digits,options\n";
    }

    private function pgbackrest(): string
    {
        $archive = $this->request->archive;

        return self::backrestConfig($archive === null ? [] : PostgresFiles::repositoryOf($archive->storage), $archive?->retentionDays);
    }

    /**
     * @param  list<array{string, string}>  $repository
     */
    public static function backrestConfig(array $repository, ?int $retentionDays): string
    {
        $retention = $retentionDays === null ? '' : "repo1-retention-full-type=time\nrepo1-retention-full={$retentionDays}\n";

        return "[global]\n".implode('', array_map(fn (array $option): string => "{$option[0]}={$option[1]}\n", $repository)).$retention
            ."repo1-bundle=y\ncompress-type=zst\nprocess-max=2\nstart-fast=y\narchive-async=y\narchive-push-queue-max=4GiB\nspool-path=/srv/postgres/archive/spool\nlock-path=/srv/postgres/archive/lock\nlog-path=/srv/postgres/archive/log\nlog-level-console=warn\nlog-level-file=info\n\n"
            ."[main]\npg1-path=/srv/postgres/data\npg1-port=".PostgresFiles::CLIENT_PORT."\npg1-socket-path=/srv/postgres/run\n";
    }

    /**
     * @param  array<string, string>  $options
     */
    private function options(array $options, string $format): string
    {
        return implode('', array_map(fn (string $key, string $value): string => sprintf($format, $key, PostgresFiles::quoted($value))."\n", array_keys($options), $options));
    }

    /**
     * @param  list<string>  $lines
     */
    private function lines(array $lines, string $format): string
    {
        return implode('', array_map(fn (string $line): string => sprintf($format, $line)."\n", $lines));
    }
}
