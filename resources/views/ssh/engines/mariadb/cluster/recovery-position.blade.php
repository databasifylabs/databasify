@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
if server_active && in_galera; then
    position=$(global_status WSREP_LAST_COMMITTED || true)
else
    stop_server
    position=$(sed -n 's/^seqno: *//p' "$dir/data/grastate.dat" 2> /dev/null || true)
    if [ -z "$position" ] || [ "$position" = "-1" ]; then
        log=$(mktemp)
        chown mysql:mysql "$log"
        mariadbd --defaults-extra-file={{ $configFile }} --user=mysql --wsrep-recover --log-error="$log" > /dev/null 2>&1 || true
        position=$(sed -n 's/.*Recovered position: [^:]*:\([0-9-]*\).*/\1/p' "$log" | tail -n 1)
        rm -f "$log"
    fi
fi
echo "position=${position:--1}"
