<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Drivers\MySqlFamily\FamilyFiles;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;
use App\Engines\Requests\NodeConfigure;

final readonly class MySqlFiles
{
    public const string HOME = '/srv/mysql';

    public const int GROUP_PORT = 33061;

    public const string NATIVE_PASSWORDS_REMOVED_IN = '9.0';

    private const string URL_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    public static function of(NodeConfigure $request, string $line): ConfigFiles
    {
        $engine = MySqlFamilyEngine::of('mysql');
        $manifest = FamilyFiles::manifestOf($engine, $request, ['/usr/sbin/mysqld', '--defaults-file='.self::HOME.'/conf/my.cnf']);

        return new ConfigFiles([
            new ConfigFile(self::HOME.'/conf/my.cnf', FamilyFiles::FILE_MODE, self::myCnfOf($request, $line)),
            new ConfigFile($engine->adminCnf(), FamilyFiles::FILE_MODE, FamilyFiles::adminCnfOf($engine->socket(), (string) $request->secrets->admin)),
            new ConfigFile(self::HOME.'/conf/agent.cnf', FamilyFiles::FILE_MODE, self::agentCnfOf((string) $request->secrets->agent)),
        ], $manifest);
    }

    public static function nativePasswords(string $line): bool
    {
        return version_compare($line, self::NATIVE_PASSWORDS_REMOVED_IN, '<');
    }

    public static function groupNameOf(string $databaseId): string
    {
        $hash = sha1((string) hex2bin(str_replace('-', '', self::URL_NAMESPACE)).$databaseId, true);
        $hash[6] = chr(ord($hash[6]) & 0x0F | 0x50);
        $hash[8] = chr(ord($hash[8]) & 0x3F | 0x80);
        $hex = bin2hex(substr($hash, 0, 16));

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    /**
     * @param  list<Member>  $members
     */
    public static function seedsOf(array $members): string
    {
        $voting = array_filter($members, fn (Member $member): bool => $member->voting);

        return implode(',', array_map(fn (Member $member): string => "{$member->ip}:".self::GROUP_PORT, $voting));
    }

    private static function myCnfOf(NodeConfigure $request, string $line): string
    {
        $lines = [
            '[mysqld]',
            'datadir='.self::HOME.'/data',
            'socket='.self::HOME.'/run/mysqld.sock',
            'pid-file='.self::HOME.'/run/mysqld.pid',
            'port='.FamilyFiles::CLIENT_PORT,
            'bind-address=*',
            "report_host={$request->ip}",
            'server_id='.FamilyFiles::serverIdOfAddress($request->ip),
            'gtid_mode=ON',
            'enforce_gtid_consistency=ON',
            'log_bin=binlog',
            'binlog_expire_logs_seconds=604800',
            'ssl_ca='.self::HOME.'/tls/ca.crt',
            'ssl_cert='.self::HOME.'/tls/server.crt',
            'ssl_key='.self::HOME.'/tls/server.key',
            'tls_version=TLSv1.2,TLSv1.3',
            'plugin-load-add=mysql_clone.so',
            'performance_schema=ON',
            'performance-schema-consumer-statements-digest=ON',
        ];

        if (self::nativePasswords($line)) {
            $lines[] = 'mysql_native_password=ON';
        }

        if ($request->clustered && ! $request->readOnly) {
            array_push($lines,
                'plugin-load-add=group_replication.so',
                'group_replication_group_name='.self::groupNameOf($request->databaseId),
                "group_replication_local_address={$request->ip}:".self::GROUP_PORT,
                'group_replication_group_seeds='.self::seedsOf($request->members),
                'group_replication_single_primary_mode=ON',
                'group_replication_start_on_boot=OFF',
                'group_replication_ssl_mode=REQUIRED',
                'group_replication_recovery_use_ssl=ON',
                'sql_require_primary_key=ON',
            );
        }

        if ($request->readOnly) {
            array_push($lines, 'super_read_only=ON', 'replica_parallel_workers=4');
        }

        $lines = FamilyFiles::withSettings($lines, $request->settings);
        $lines[] = '!includedir '.self::HOME.'/conf/conf.d';

        return implode("\n", $lines)."\n";
    }

    private static function agentCnfOf(string $password): string
    {
        return "[client]\nuser=databasify_agent\npassword={$password}\nsocket=".self::HOME."/run/mysqld.sock\n\n[xtrabackup]\ndatadir=".self::HOME."/data\n";
    }
}
