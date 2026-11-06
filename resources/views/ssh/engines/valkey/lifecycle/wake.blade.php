databasify ctl stop {{ $sentinelUnit }} > /dev/null 2>&1 || true
databasify ctl kill -s KILL {{ $serverUnit }} > /dev/null 2>&1 || true
databasify ctl stop {{ $serverUnit }} > /dev/null 2>&1 || true
echo "{{ $flavor }} stopped without saving, so dump.rdb keeps the replication offset of its last clean stop"
