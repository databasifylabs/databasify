@include('ssh.engines.valkey.helpers')
@include('ssh.databases.helpers')
@php($hosts ??= '')
removed_clients() {
    awk -v keep=" {{ str_replace(',', ' ', $hosts) }} 127.0.0.1 " -v port="$2" '{
        id = ""; ip = ""; lport = ""
        for (i = 1; i <= NF; i++) {
            if ($i ~ /^id=/) { id = substr($i, 4) }
            if ($i ~ /^addr=/) { ip = substr($i, 6); sub(/:[0-9]+$/, "", ip) }
            if ($i ~ /^laddr=/) { lport = $i; sub(/.*:/, "", lport) }
        }
        if (id != "" && lport == port && index(keep, " " ip " ") == 0) { print id }
    }' <<< "$1"
}
drop_removed_nodes() {
@if ($hosts !== '')
    for id in $(removed_clients "$(vcli CLIENT LIST)" {{ $dataPort }}); do vcli CLIENT KILL ID "$id" > /dev/null || true; done
    for id in $(removed_clients "$(scli CLIENT LIST)" {{ $sentinelPort }}); do scli CLIENT KILL ID "$id" > /dev/null || true; done
@endif
    scli SENTINEL RESET main > /dev/null
}
peers_seen() {
    seen=$(scli SENTINEL sentinels main | grep -c '^name$' || true)
    if [ "$seen" -gt {{ $peers }} ]; then
        drop_removed_nodes
        return 1
    fi
    [ "$seen" -ge {{ $peers }} ]
}
drop_removed_nodes
wait_for 120 peers_seen
echo "sentinel forgot removed nodes and sees {{ $peers }} peers again"
