<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MariaDb;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Drivers\MySqlFamily\FamilyFiles;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;
use App\Engines\Requests\NodeConfigure;

final readonly class MariaDbFiles
{
    public const string HOME = '/srv/mariadb';

    public const string WATCH_UNIT = 'mariadb-cluster-watch';

    public const string PARSEC_SINCE = '11.6';

    private const int GALERA_NAME_LIMIT = 32;

    private const int DOMAIN_OFFSET = 10;

    private const string TLS = self::HOME.'/tls/';

    public static function of(NodeConfigure $request, string $line, bool $newCluster): ConfigFiles
    {
        $engine = MySqlFamilyEngine::of('mariadb');

        return new ConfigFiles([
            new ConfigFile(self::HOME.'/conf/my.cnf', FamilyFiles::FILE_MODE, self::myCnfOf($request, $line, $engine)),
            new ConfigFile($engine->adminCnf(), FamilyFiles::FILE_MODE, FamilyFiles::adminCnfOf($engine->socket(), (string) $request->secrets->admin)),
            new ConfigFile(self::HOME.'/conf/agent.cnf', FamilyFiles::FILE_MODE, self::agentCnfOf((string) $request->secrets->agent, $engine)),
        ], self::manifestOf($request, $newCluster, $engine));
    }

    public static function galera(NodeConfigure $request): bool
    {
        return $request->clustered && ! $request->readOnly;
    }

    public static function parsecOffered(string $line): bool
    {
        return version_compare($line, self::PARSEC_SINCE, '>=');
    }

    public static function clusterNameOf(string $databaseId): string
    {
        $name = str_replace('-', '', $databaseId);

        return strlen($name) <= self::GALERA_NAME_LIMIT ? $name : substr(hash('sha256', $databaseId), 0, self::GALERA_NAME_LIMIT);
    }

    public static function initStatementsOf(NodeConfigure $request): string
    {
        $statements = "FLUSH PRIVILEGES;\nALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD(".FamilyFiles::sqlString((string) $request->secrets->admin).") OR unix_socket;\n";

        if (! self::galera($request) || $request->secrets->replication === null) {
            return $statements;
        }

        return $statements."CREATE USER 'databasify_sst'@'localhost' IDENTIFIED BY ".FamilyFiles::sqlString($request->secrets->replication).";\n"
            ."GRANT RELOAD, PROCESS, LOCK TABLES, BINLOG MONITOR ON *.* TO 'databasify_sst'@'localhost';\n";
    }

    private static function manifestOf(NodeConfigure $request, bool $newCluster, MySqlFamilyEngine $engine): string
    {
        $argv = ['/usr/sbin/mariadbd', '--defaults-file='.self::HOME.'/conf/my.cnf'];

        if ($newCluster) {
            $argv[] = '--wsrep-new-cluster';
        }

        $watch = $request->clustered ? [FamilyFiles::helper(self::WATCH_UNIT, 'cluster-watch', $engine->serverUnit(), '3s')] : [];

        return FamilyFiles::manifestOf($engine, $request, $argv, $watch);
    }

    private static function myCnfOf(NodeConfigure $request, string $line, MySqlFamilyEngine $engine): string
    {
        $lines = [
            '[mysqld]',
            'datadir='.self::HOME.'/data',
            'socket='.$engine->socket(),
            'pid-file='.self::HOME.'/run/mariadbd.pid',
            'port='.FamilyFiles::CLIENT_PORT,
            'bind-address=*',
            'server_id='.self::serverIdOf($request),
            'gtid_domain_id='.self::gtidDomainOf($request),
            'log_bin=binlog',
            'log_slave_updates=ON',
            'binlog_format=ROW',
            'binlog_expire_logs_seconds=604800',
            'ssl_ca='.self::TLS.'ca.crt',
            'ssl_cert='.self::TLS.'server.crt',
            'ssl_key='.self::TLS.'server.key',
            'tls_version=TLSv1.2,TLSv1.3',
            'plugin_load_add=auth_ed25519',
            'performance_schema=ON',
            'performance_schema_digests_size=10000',
        ];

        if (self::parsecOffered($line)) {
            $lines[] = 'plugin_load_add=auth_parsec';
        }

        if (self::galera($request)) {
            array_push($lines,
                'wsrep_on=ON',
                'wsrep_provider=/usr/lib/galera/libgalera_smm.so',
                'wsrep_cluster_name='.self::clusterNameOf($request->databaseId),
                'wsrep_cluster_address='.self::clusterAddressOf($request->members),
                "wsrep_node_address={$request->ip}",
                "wsrep_node_name={$request->ip}",
                "wsrep_node_incoming_address={$request->ip}:".FamilyFiles::CLIENT_PORT,
                'wsrep_sst_method=mariabackup',
                'wsrep_sst_auth=databasify_sst:'.$request->secrets->replication,
                'wsrep_gtid_mode=ON',
                'wsrep_gtid_domain_id=1',
                'wsrep_provider_options="socket.ssl_key='.self::TLS.'server.key;socket.ssl_cert='.self::TLS.'server.crt;socket.ssl_ca='.self::TLS.'ca.crt;gcache.size=1G"',
                'innodb_autoinc_lock_mode=2',
                'innodb_force_primary_key=ON',
            );
        }

        if ($request->readOnly || self::galera($request)) {
            $lines[] = 'read_only=ON';
        }

        $lines = FamilyFiles::withSettings($lines, $request->settings);

        if (self::galera($request)) {
            array_push($lines, '', '[sst]', 'ssl-mode=VERIFY_CA');
        }

        return implode("\n", $lines)."\n";
    }

    private static function serverIdOf(NodeConfigure $request): int
    {
        if (! self::galera($request)) {
            return FamilyFiles::serverIdOfAddress($request->ip);
        }

        return crc32($request->databaseId) % (1 << 31) | 1;
    }

    private static function gtidDomainOf(NodeConfigure $request): int
    {
        foreach ($request->members as $member) {
            if ($member->ip === $request->ip) {
                return $member->position + self::DOMAIN_OFFSET;
            }
        }

        return self::DOMAIN_OFFSET;
    }

    /**
     * @param  list<Member>  $members
     */
    private static function clusterAddressOf(array $members): string
    {
        $voting = array_filter($members, fn (Member $member): bool => $member->voting);

        return 'gcomm://'.implode(',', array_map(fn (Member $member): string => $member->ip, $voting));
    }

    private static function agentCnfOf(string $password, MySqlFamilyEngine $engine): string
    {
        return "[client]\nuser=databasify_agent\npassword={$password}\nsocket={$engine->socket()}\n\n[mariadb-backup]\ndatadir=".self::HOME."/data\n";
    }
}
