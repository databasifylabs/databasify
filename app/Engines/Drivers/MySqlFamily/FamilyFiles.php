<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Drivers\GoJson;
use App\Engines\Requests\NodeConfigure;

final readonly class FamilyFiles
{
    public const int CLIENT_PORT = 25060;

    public const int FILE_MODE = 0o600;

    private const string WRAPPER_PATH = '/usr/local/bin/databasify';

    public static function adminCnfOf(string $socket, string $password): string
    {
        return "[client]\nuser=root\npassword={$password}\nsocket={$socket}\n";
    }

    public static function serverIdOfAddress(string $ip): int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) ip2long($ip) | 1;
        }

        return crc32($ip) | 1;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string>  $settings
     * @return list<string>
     */
    public static function withSettings(array $lines, array $settings): array
    {
        ksort($settings, SORT_STRING);

        foreach ($settings as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $extra
     */
    public static function manifestOf(MySqlFamilyEngine $engine, NodeConfigure $request, array $server, array $extra = []): string
    {
        $server = ['name' => $engine->serverUnit(), 'argv' => $server, 'user' => 'mysql', 'restart' => 'always', 'restartSec' => '3s', 'stopSignal' => 'TERM', 'stopTimeout' => '10m0s', 'enabled' => true];

        if ($request->archive === null) {
            return GoJson::indented(['processes' => [$server, ...$extra], 'schedules' => []]);
        }

        $units = $engine->archiveUnits();

        return GoJson::indented([
            'processes' => [$server, self::helper($units['stream'], 'archive-stream', $engine->serverUnit(), '10s'), ...$extra],
            'schedules' => [
                ['name' => $units['ship'], 'every' => '1m0s', 'argv' => self::taskArgv('archive-ship'), 'timeout' => '1h0m0s'],
                ['name' => $units['backup'], 'onCalendar' => sprintf('%02d:00', $request->archive->backupHour), 'argv' => self::taskArgv('backup'), 'timeout' => '24h0m0s'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function helper(string $name, string $task, string $after, string $restartWait): array
    {
        return ['name' => $name, 'argv' => self::taskArgv($task), 'after' => [$after], 'restart' => 'always', 'restartSec' => $restartWait, 'stopSignal' => 'TERM', 'stopTimeout' => '10s', 'enabled' => true];
    }

    public static function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }

    /**
     * @return list<string>
     */
    private static function taskArgv(string $task): array
    {
        return [self::WRAPPER_PATH, 'engine-task', $task];
    }
}
