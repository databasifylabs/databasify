<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\NodeConfig;
use App\Engines\Data\Storage;
use App\Engines\Drivers\Support\ConfigView;
use App\Engines\Drivers\Support\IndentedJson;

final readonly class FamilyFiles
{
    public const int CLIENT_PORT = 25060;

    public const int FILE_MODE = 0o600;

    private const int STREAM_ID_BIT = 0x80000000;

    public static function adminCnfOf(string $socket, string $password): string
    {
        return ConfigView::render('engines.mysql-family.files.admin-cnf', ['socket' => $socket, 'password' => $password]);
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    public static function sorted(array $settings): array
    {
        ksort($settings, SORT_STRING);

        return $settings;
    }

    public static function serverIdOfAddress(string $ip): int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) ip2long($ip) | 1;
        }

        return crc32($ip) | 1;
    }

    /**
     * @param  list<string>  $argv
     * @param  list<array<string, mixed>>  $extra
     */
    public static function manifestOf(MySqlFamilyEngine $engine, NodeConfig $request, array $argv, array $extra = []): string
    {
        $server = ['name' => $engine->serverUnit(), 'argv' => $argv, 'user' => 'mysql', 'restart' => 'always', 'restartSec' => '3s', 'stopSignal' => 'TERM', 'stopTimeout' => '10m0s', 'enabled' => true];

        if ($request->archive === null) {
            return IndentedJson::of(['processes' => [$server, ...$extra], 'schedules' => []]);
        }

        $units = $engine->archiveUnits();
        $storage = self::storageEnvOf($request->archive->storage);
        $streamEnv = ['STREAM_SERVER_ID' => (string) (self::serverIdOfAddress($request->ip) ^ self::STREAM_ID_BIT)];
        $backupEnv = [...$storage, 'RETENTION_DAYS' => (string) $request->archive->retentionDays, 'BACKUP_GALERA_INFO' => $engine->id === 'mariadb' && $request->clustered && ! $request->readOnly ? 'true' : 'false'];

        return IndentedJson::of([
            'processes' => [$server, self::helper($units['stream'], self::jobOf($engine, 'binlog-stream'), $engine->serverUnit(), '10s', $streamEnv), ...$extra],
            'schedules' => [
                ['name' => $units['ship'], 'every' => '1m0s', 'argv' => ['bash', self::jobOf($engine, 'binlog-ship')], 'env' => $storage, 'timeout' => '1h0m0s'],
                ['name' => $units['backup'], 'onCalendar' => sprintf('%02d:00', $request->archive->backupHour), 'argv' => ['bash', self::jobOf($engine, 'backup')], 'env' => $backupEnv, 'timeout' => '24h0m0s'],
            ],
        ]);
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    public static function helper(string $name, string $job, string $after, string $restartWait, array $env = []): array
    {
        return ['name' => $name, 'argv' => ['bash', $job], ...($env === [] ? [] : ['env' => $env]), 'after' => [$after], 'restart' => 'always', 'restartSec' => $restartWait, 'stopSignal' => 'TERM', 'stopTimeout' => '10s', 'enabled' => true];
    }

    public static function jobOf(MySqlFamilyEngine $engine, string $name): string
    {
        return "{$engine->home}/conf/{$name}.sh";
    }

    /**
     * @return array{RCLONE_REMOTE: string, STORAGE_PATH: string, RCLONE_S3_ACCESS_KEY_ID: string, RCLONE_S3_SECRET_ACCESS_KEY: string, RCLONE_CONFIG: string}
     */
    public static function storageEnvOf(Storage $storage): array
    {
        return [
            'RCLONE_REMOTE' => sprintf(':s3,provider=Other,endpoint="%s",region="%s",force_path_style=%s,no_check_bucket=true:%s/', $storage->endpoint, $storage->region, $storage->pathStyle ? 'true' : 'false', $storage->bucket),
            'STORAGE_PATH' => trim($storage->path, '/'),
            'RCLONE_S3_ACCESS_KEY_ID' => $storage->accessKey,
            'RCLONE_S3_SECRET_ACCESS_KEY' => $storage->secretKey,
            'RCLONE_CONFIG' => '/dev/null',
        ];
    }

    public static function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
