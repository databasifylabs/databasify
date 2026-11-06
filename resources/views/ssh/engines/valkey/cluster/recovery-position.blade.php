@include('ssh.engines.valkey.helpers')
position=''
if databasify ctl is-active --quiet {{ $serverUnit }}; then
    position=$(vcli INFO replication | sed -n 's/^master_repl_offset://p' || true)
fi
if [ -z "$position" ] || [ "$position" = 0 ]; then
    stored=$({ {{ $checkRdb }} {{ $directory }}/data/dump.rdb 2> /dev/null || true; } | sed -n -e "s/.*AUX FIELD repl-offset = '\([0-9]*\)'.*/\1/p" -e '/Selecting DB/q')
    position=${stored:-$position}
fi
if [ -z "$position" ]; then
    if has_data; then
        position=0
    else
        position=-1
    fi
fi
echo "position=$position"
