<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Data\Storage;
use App\Engines\Drivers\GoJson;
use App\Engines\Requests\NodeConfigure;

final readonly class MongoDbFiles
{
    public const string HOME = '/srv/mongodb';

    public const int CLIENT_PORT = 25060;

    public const string SERVER_UNIT = 'mongod';

    public const string AGENT_UNIT = 'pbm-agent';

    public const string BACKUP_UNIT = 'mongodb-backup';

    private const string RAW_NUMBER = "\0number:";

    private const string USERINFO_KEPT = "$&'()*+,;=!~";

    /**
     * @param  array<string, string>  $effective
     */
    public static function mongodConfOf(array $effective): string
    {
        $defaults = [];

        foreach (MongoDbSettings::catalog()->fields as $field) {
            $defaults[$field->key] = $field->default;
        }

        $value = fn (string $key): string => self::RAW_NUMBER.(is_numeric($effective[$key] ?? null) ? $effective[$key] : $defaults[$key]);

        $conf = [
            'systemLog' => ['verbosity' => $value(MongoDbSettings::VERBOSITY)],
            'operationProfiling' => ['mode' => 'slowOp', 'slowOpThresholdMs' => $value(MongoDbSettings::SLOW_OPERATION)],
            'setParameter' => [MongoDbSettings::TRANSACTIONS => $value(MongoDbSettings::TRANSACTIONS)],
            'net' => [
                'port' => self::CLIENT_PORT, 'bindIpAll' => true, 'maxIncomingConnections' => $value(MongoDbSettings::CONNECTIONS),
                'tls' => [
                    'mode' => 'requireTLS', 'certificateKeyFile' => self::HOME.'/tls/server.pem', 'CAFile' => self::HOME.'/tls/ca.crt',
                    'allowConnectionsWithoutCertificates' => true, 'disabledProtocols' => 'TLS1_0,TLS1_1',
                ],
            ],
            'storage' => ['dbPath' => self::HOME.'/data', 'wiredTiger' => ['engineConfig' => [MongoDbSettings::CACHE_SIZE => $value(MongoDbSettings::CACHE_SIZE)]]],
            'security' => ['keyFile' => self::HOME.'/conf/keyfile', 'authorization' => 'enabled'],
            'replication' => ['replSetName' => 'databasify'],
        ];

        return (string) preg_replace('/"\\\\u0000number:([^"]*)"/', '$1', GoJson::indented(self::sorted($conf)))."\n";
    }

    public static function keyfileOf(string $replication): string
    {
        return base64_encode(hash_hmac('sha256', 'mongodb keyfile', $replication, true));
    }

    public static function manifestOf(NodeConfigure $request): string
    {
        $processes = [[
            'name' => self::SERVER_UNIT, 'argv' => ['mongod', '--config', self::HOME.'/conf/mongod.conf'], 'user' => 'mongodb',
            'restart' => 'always', 'restartSec' => '3s', 'stopSignal' => 'TERM', 'stopTimeout' => '15m0s', 'enabled' => true,
        ]];
        $schedules = [];

        if ($request->archive !== null && $request->secrets->agent !== null) {
            $processes[] = [
                'name' => self::AGENT_UNIT, 'argv' => ['pbm-agent'], 'user' => 'mongodb', 'env' => ['PBM_MONGODB_URI' => self::pbmUriOf($request->secrets->agent)],
                'after' => [self::SERVER_UNIT], 'restart' => 'always', 'restartSec' => '3s', 'stopSignal' => 'TERM', 'stopTimeout' => '1m0s', 'enabled' => true,
            ];
            $schedules[] = ['name' => self::BACKUP_UNIT, 'onCalendar' => sprintf('%02d:00', $request->archive->backupHour), 'argv' => ['/usr/local/bin/databasify', 'engine-task', 'backup'], 'timeout' => '24h0m0s'];
        }

        return GoJson::indented(['processes' => $processes, 'schedules' => $schedules]);
    }

    public static function pbmUriOf(string $password): string
    {
        return 'mongodb://databasify_pbm:'.self::userinfoOf($password).'@127.0.0.1:'.self::CLIENT_PORT.'/?authSource=admin&tls=true&tlsInsecure=true';
    }

    public static function pbmConfigOf(Storage $storage, bool $slicing): string
    {
        return GoJson::indented(self::sorted([
            'storage' => [
                'type' => 's3',
                's3' => [
                    'region' => $storage->region, 'bucket' => $storage->bucket, 'prefix' => $storage->path, 'endpointUrl' => $storage->endpoint,
                    'forcePathStyle' => $storage->pathStyle, 'insecureSkipTLSVerify' => false,
                    'credentials' => ['access-key-id' => $storage->accessKey, 'secret-access-key' => $storage->secretKey],
                ],
            ],
            'pitr' => ['enabled' => $slicing, 'oplogSpanMin' => 10],
            'backup' => ['compression' => 'zstd'],
        ]))."\n";
    }

    public static function userinfoOf(string $value): string
    {
        return strtr(rawurlencode($value), array_combine(
            array_map(rawurlencode(...), str_split(self::USERINFO_KEPT)),
            str_split(self::USERINFO_KEPT),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>
     */
    private static function sorted(array $map): array
    {
        ksort($map, SORT_STRING);

        foreach ($map as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $map[$key] = self::sorted($value);
            }
        }

        return $map;
    }
}
