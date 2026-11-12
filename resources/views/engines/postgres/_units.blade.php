manifest_units() {
    if [ ! -f /srv/postgres/conf/processes.json ]; then
        databasify_fail unavailable 'this node has no configuration yet; run node configure first'
    fi
    jq -r '.processes[].name' /srv/postgres/conf/processes.json
}
