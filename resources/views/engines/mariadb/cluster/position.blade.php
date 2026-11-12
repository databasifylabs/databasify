@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
position=-1
if unit_active mariadb-server; then
    my_try '' <<< "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_LAST_COMMITTED'"
    if [ -z "$MY_ERROR" ] && [ -n "$MY_OUTPUT" ]; then
        position=$MY_OUTPUT
    fi
else
    saved_seqno=$(sed -n 's/^seqno:[[:space:]]*\(-\{0,1\}[0-9][0-9]*\).*/\1/p' "$ROOT/data/grastate.dat" 2> /dev/null | head -n 1 || true)
    if [ -n "$saved_seqno" ] && [ "$saved_seqno" != -1 ]; then
        position=$saved_seqno
    else
        recovered=$(/usr/sbin/mariadbd --defaults-file="$ROOT/conf/my.cnf" --wsrep-recover --user=mysql 2>&1 || true)
        found=$(grep -o 'WSREP: Recovered position: [0-9a-f-]*:-\{0,1\}[0-9][0-9]*' <<< "$recovered" | head -n 1 | sed 's/.*://')
        if [ -n "$found" ]; then
            position=$found
        fi
    fi
fi
if ! [[ $position =~ ^-?[0-9]+$ ]]; then
    position=0
fi
result "{\"position\":$position}"
