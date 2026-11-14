<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MariaDb;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\MySqlFamily\FamilyFiles;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;
use App\Engines\Drivers\Support\ConfigView;
use App\Engines\Drivers\Support\NodeState;

final readonly class MariaDbFiles
{
    public const string HOME = '/srv/mariadb';

    public const string WATCH_UNIT = 'mariadb-cluster-watch';

    public const string PARSEC_SINCE = '11.6';

    private const int GALERA_NAME_LIMIT = 32;

    private const int DOMAIN_OFFSET = 10;

    private const string TLS = self::HOME.'/tls/';

    public static function of(NodeConfig $request, string $line, bool $newCluster): ConfigFiles
    {
        $engine = MySqlFamilyEngine::of('mariadb');

        return new ConfigFiles([
            new ConfigFile(self::HOME.'/conf/my.cnf', FamilyFiles::FILE_MODE, self::myCnfOf($request, $line, $engine)),
            new ConfigFile($engine->adminCnf(), FamilyFiles::FILE_MODE, FamilyFiles::adminCnfOf($engine->socket(), (string) $request->secrets->admin)),
            new ConfigFile(self::HOME.'/conf/agent.cnf', FamilyFiles::FILE_MODE, self::agentCnfOf((string) $request->secrets->agent, $engine)),
        ], self::manifestOf($request, $newCluster, $engine));
    }

    public static function galera(NodeConfig $request): bool
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

    public static function initStatementsOf(NodeConfig $request): string
    {
        $statements = "FLUSH PRIVILEGES;\nALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD(".FamilyFiles::sqlString((string) $request->secrets->admin).") OR unix_socket;\n";

        if (! self::galera($request) || $request->secrets->replication === null) {
            return $statements;
        }

        return $statements."CREATE USER 'databasify_sst'@'localhost' IDENTIFIED BY ".FamilyFiles::sqlString($request->secrets->replication).";\n"
            ."GRANT RELOAD, PROCESS, LOCK TABLES, BINLOG MONITOR ON *.* TO 'databasify_sst'@'localhost';\n";
    }

    private static function manifestOf(NodeConfig $request, bool $newCluster, MySqlFamilyEngine $engine): string
    {
        $argv = ['/usr/sbin/mariadbd', '--defaults-file='.self::HOME.'/conf/my.cnf'];

        if ($newCluster) {
            $argv[] = '--wsrep-new-cluster';
        }

        $watch = $request->clustered ? [FamilyFiles::helper(self::WATCH_UNIT, FamilyFiles::jobOf($engine, 'cluster-watch'), $engine->serverUnit(), '3s')] : [];

        return FamilyFiles::manifestOf($engine, $request, $argv, $watch);
    }

    private static function myCnfOf(NodeConfig $request, string $line, MySqlFamilyEngine $engine): string
    {
        return ConfigView::render('engines.mariadb.files.my-cnf', [
            'config' => $request,
            'home' => self::HOME,
            'socket' => $engine->socket(),
            'tls' => self::TLS,
            'port' => FamilyFiles::CLIENT_PORT,
            'serverId' => self::serverIdOf($request),
            'gtidDomain' => self::gtidDomainOf($request),
            'parsec' => self::parsecOffered($line),
            'galera' => self::galera($request),
            'clusterName' => self::clusterNameOf($request->databaseId),
            'clusterAddress' => self::clusterAddressOf($request->members),
            'settings' => FamilyFiles::sorted($request->settings),
        ]);
    }

    private static function serverIdOf(NodeConfig $request): int
    {
        if (! self::galera($request)) {
            return FamilyFiles::serverIdOfAddress($request->ip);
        }

        return crc32($request->databaseId) % (1 << 31) | 1;
    }

    private static function gtidDomainOf(NodeConfig $request): int
    {
        return NodeState::selfOf($request)->position + self::DOMAIN_OFFSET;
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
        return ConfigView::render('engines.mariadb.files.agent-cnf', ['password' => $password, 'socket' => $engine->socket(), 'home' => self::HOME]);
    }
}
