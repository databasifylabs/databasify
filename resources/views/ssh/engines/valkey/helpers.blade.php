vcli() { VALKEYCLI_AUTH=$(cat {{ $directory }}/conf/password) timeout 30 {{ $cli }} -h 127.0.0.1 -p 6379 "$@" </dev/null 2>/dev/null | tr -d '\r'; }
scli() { VALKEYCLI_AUTH=$(cat {{ $directory }}/conf/password) timeout 30 {{ $cli }} -h 127.0.0.1 -p 26379 "$@" </dev/null 2>/dev/null | tr -d '\r'; }
conf={{ $directory }}/conf/{{ $flavor }}.conf
start_refused={{ $directory }}/conf/start-refused
has_data() { [ -s {{ $directory }}/data/dump.rdb ] || [ -n "$(ls -A {{ $directory }}/data/appendonlydir 2> /dev/null)" ]; }
restart_loses_data() { grep -q '^appendonly no$' "$conf" || ! has_data; }
aof_written() { local info; info=$(vcli INFO persistence); grep -q '^aof_rewrite_in_progress:0$' <<< "$info" && grep -q '^aof_rewrite_scheduled:0$' <<< "$info"; }
