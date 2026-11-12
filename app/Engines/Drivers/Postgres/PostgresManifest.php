<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Requests\NodeConfigure;

final readonly class PostgresManifest
{
    public const string LOCKS_UNIT = 'databasify-postgres-locks';

    public const string ETCD_UNIT = 'databasify-etcd';

    public const string SERVER_UNIT = 'databasify-postgres';

    public const string PATRONI_UNIT = 'databasify-patroni';

    public const string POOLER_UNIT = 'databasify-pgbouncer';

    public const string BACKUP_UNIT = 'databasify-postgres-backup';

    private const string STOP_TIMEOUT = '2m';

    private const string RESTART_WAIT = '3s';

    private const string WRAPPER_PATH = '/usr/local/bin/databasify';

    private const string LOCKS_SCRIPT = 'pgrep -x postgres > /dev/null || rm -f /srv/postgres/run/.s.PGSQL.* /srv/postgres/data/postmaster.pid';

    private const string ETCD_SCRIPT = <<<'SH'
set -a; . /srv/postgres/conf/etcd.env; for extra in /srv/postgres/conf/etcd-initial.env /srv/postgres/conf/etcd-force.env; do [ ! -f "$extra" ] || . "$extra"; done; set +a
peers() { awk -F "'" '/^  hosts: / { print $2 }' /srv/postgres/conf/patroni.yml 2>/dev/null | tr ',' '\n' | grep -v "^$ETCD_NAME:"; }
if [ "$ETCD_INITIAL_CLUSTER_STATE" = new ] && [ ! -d "$ETCD_DATA_DIR/member" ]; then
    for endpoint in $(peers); do
        if timeout 10 etcdctl --endpoints="http://$endpoint" member list 2>/dev/null | awk -F ', ' -v peer="$ETCD_INITIAL_ADVERTISE_PEER_URLS" '$4 == peer { listed = 1 } END { exit !listed }'; then
            echo "etcd on $ETCD_NAME lost its data while http://$endpoint still lists it, waiting until it is registered again"
            exit 1
        fi
    done
fi
if [ "$ETCD_INITIAL_CLUSTER_STATE" = existing ] && [ ! -d "$ETCD_DATA_DIR/member" ]; then
    for endpoint in $(peers); do
        cluster=$(timeout 10 etcdctl --endpoints="http://$endpoint" member list 2>/dev/null | awk -F ', ' -v peer="$ETCD_INITIAL_ADVERTISE_PEER_URLS" -v self="$ETCD_NAME" '{ name = ($4 == peer) ? self : $3; printf "%s%s=%s", separator, name, $4; separator = "," }')
        if [ -n "$cluster" ]; then
            export ETCD_INITIAL_CLUSTER="$cluster"
            break
        fi
    done
fi
exec /usr/local/bin/etcd
SH;

    public static function of(NodeConfigure $request, string $line): string
    {
        $manifest = ['processes' => self::processesOf($request, $line)];

        if ($request->archive !== null) {
            $manifest['schedules'] = [[
                'name' => self::BACKUP_UNIT,
                'onCalendar' => sprintf('%02d:00', $request->archive->backupHour),
                'timeout' => '24h',
                'argv' => [self::WRAPPER_PATH, 'engine-task', 'backup'],
            ]];
        }

        return self::goJson($manifest);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function processesOf(NodeConfigure $request, string $line): array
    {
        $locks = ['name' => self::LOCKS_UNIT, 'type' => 'oneshot', 'user' => 'postgres', 'argv' => ['sh', '-c', self::LOCKS_SCRIPT], 'enabled' => true];
        $pooler = self::daemon(self::POOLER_UNIT, ['/usr/sbin/pgbouncer', PostgresFiles::CONF.'/pgbouncer.ini'], self::SERVER_UNIT, self::PATRONI_UNIT);

        if (! $request->clustered) {
            $server = self::daemon(self::SERVER_UNIT, ["/usr/lib/postgresql/{$line}/bin/postgres", '-D', '/srv/postgres/data', '-c', 'config_file='.PostgresFiles::CONF.'/postgresql.conf'], self::LOCKS_UNIT);

            return [$locks, self::stopping($server, 'SIGINT'), $pooler];
        }

        $patroni = self::stopping(self::daemon(self::PATRONI_UNIT, ['/usr/bin/patroni', PostgresFiles::CONF.'/patroni.yml'], self::LOCKS_UNIT, self::ETCD_UNIT), 'SIGTERM');

        if ($request->readOnly) {
            return [$locks, $patroni, $pooler];
        }

        return [$locks, self::daemon(self::ETCD_UNIT, ['sh', '-c', self::ETCD_SCRIPT]), $patroni, $pooler];
    }

    /**
     * @param  list<string>  $argv
     * @return array<string, mixed>
     */
    private static function daemon(string $name, array $argv, string ...$after): array
    {
        $process = ['name' => $name, 'user' => 'postgres'];

        if ($after !== []) {
            $process['after'] = array_values($after);
        }

        return [...$process, 'restart' => 'always', 'restartSec' => self::RESTART_WAIT, 'argv' => $argv, 'enabled' => false];
    }

    /**
     * @param  array<string, mixed>  $process
     * @return array<string, mixed>
     */
    private static function stopping(array $process, string $signal): array
    {
        $argv = $process['argv'];
        $enabled = $process['enabled'];
        unset($process['argv'], $process['enabled']);

        return [...$process, 'stopSignal' => $signal, 'stopTimeout' => self::STOP_TIMEOUT, 'argv' => $argv, 'enabled' => $enabled];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function goJson(array $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $twoSpaces = (string) preg_replace_callback('/^( +)/m', fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);

        return strtr($twoSpaces, ['<' => '\u003c', '>' => '\u003e', '&' => '\u0026']);
    }
}
