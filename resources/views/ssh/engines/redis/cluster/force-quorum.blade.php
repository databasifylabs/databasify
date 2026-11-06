@include('ssh.engines.redis.cluster.recovery-helpers')
@include('ssh.databases.helpers')
databasify ctl reset-failed {{ $serverUnit }} {{ $sentinelUnit }} > /dev/null 2>&1 || true
databasify ctl start {{ $sentinelUnit }} > /dev/null 2>&1 || true
found() { primary=$(newest_primary || true); [ -n "$primary" ]; }
if wait_for 20 found > /dev/null; then
    if [ "$primary" != '{{ $privateIp }}' ] && ! databasify ctl is-active --quiet {{ $serverUnit }}; then
        sed -i '/^replicaof /d' "$conf"
        echo "replicaof $primary {{ $dataPort }}" >> "$conf"
        databasify ctl start {{ $serverUnit }}
    fi
    echo "sentinel already serves from $primary, nothing to force"
    exit 0
fi
epoch=$(( $(highest_epoch) + 1 ))
databasify ctl stop {{ $sentinelUnit }}
sed -i \
    -e 's/^sentinel monitor {{ $master }} [^ ]* /sentinel monitor {{ $master }} {{ $privateIp }} /' \
    -e '/^sentinel config-epoch /d' -e '/^sentinel leader-epoch /d' -e '/^sentinel current-epoch /d' -e '/^sentinel known-replica /d' \
    "$sentinel_conf"
printf 'sentinel config-epoch {{ $master }} %s\nsentinel leader-epoch {{ $master }} %s\nsentinel current-epoch %s\n' "$epoch" "$epoch" "$epoch" >> "$sentinel_conf"
sed -i -e '/^replicaof /d' -e '/^replica-priority 0$/d' "$conf"
rm -f "$start_refused"
if databasify ctl is-active --quiet {{ $serverUnit }}; then
    vcli REPLICAOF NO ONE > /dev/null || true
    vcli CONFIG SET replica-priority 100 > /dev/null || true
else
    databasify ctl start {{ $serverUnit }}
fi
serves() { vcli INFO replication | grep -q '^role:master'; }
wait_for 600 serves
databasify ctl start {{ $sentinelUnit }}
sentinel_up() { scli PING | grep -q PONG; }
wait_for 60 sentinel_up
echo "{{ $instance }} serves as the primary again, sentinel config epoch $epoch"
