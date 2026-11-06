set -a; . /srv/postgres/conf/etcd.env; for extra in /srv/postgres/conf/etcd-initial.env /srv/postgres/conf/etcd-force.env; do [ ! -f "$extra" ] || . "$extra"; done; set +a
if [ "$ETCD_INITIAL_CLUSTER_STATE" = new ] && [ ! -d "$ETCD_DATA_DIR/member" ]; then
    for endpoint in $(awk -F "'" '/^  hosts: / { print $2 }' /srv/postgres/conf/patroni.yml 2>/dev/null | tr ',' '\n' | grep -v "^$ETCD_NAME:"); do
        if timeout 10 etcdctl --endpoints="http://$endpoint" member list 2>/dev/null | awk -F ', ' -v peer="$ETCD_INITIAL_ADVERTISE_PEER_URLS" '$4 == peer { listed = 1 } END { exit !listed }'; then
            echo "etcd on $ETCD_NAME lost its data while http://$endpoint still lists it, waiting until it is registered again"
            exit 1
        fi
    done
fi
exec /usr/local/bin/etcd
