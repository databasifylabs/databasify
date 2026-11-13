<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\MySqlFamily\FamilyFiles;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;

final readonly class MySqlFiles
{
    public const string HOME = '/srv/mysql';

    public const int GROUP_PORT = 33061;

    public const string NATIVE_PASSWORDS_REMOVED_IN = '9.0';

    private const string URL_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    public static function of(NodeConfig $request, string $line): ConfigFiles
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

    private static function myCnfOf(NodeConfig $request, string $line): string
    {
        return FamilyFiles::render('engines.mysql.files.my-cnf', [
            'config' => $request,
            'home' => self::HOME,
            'port' => FamilyFiles::CLIENT_PORT,
            'serverId' => FamilyFiles::serverIdOfAddress($request->ip),
            'nativePasswords' => self::nativePasswords($line),
            'groupReplication' => $request->clustered && ! $request->readOnly,
            'groupName' => self::groupNameOf($request->databaseId),
            'groupPort' => self::GROUP_PORT,
            'seeds' => self::seedsOf($request->members),
            'settings' => FamilyFiles::sorted($request->settings),
        ]);
    }

    private static function agentCnfOf(string $password): string
    {
        return FamilyFiles::render('engines.mysql.files.agent-cnf', ['password' => $password, 'home' => self::HOME]);
    }
}
