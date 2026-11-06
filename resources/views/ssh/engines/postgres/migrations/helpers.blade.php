state={{ $stateDir }}
in_pg() { setpriv --reuid=postgres --regid=postgres --init-groups env HOME=/var/lib/postgresql PGHOST={{ $socketDirectory }} PGPORT={{ $clientPort }} "$@" </dev/null; }
