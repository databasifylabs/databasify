firewalld_active() {
    command -v firewall-cmd > /dev/null 2>&1 && firewall-cmd --state > /dev/null 2>&1
}

firewalld_exclusively() {
    (
        flock -w 300 9
        "$@"
    ) 9> /run/databasify-firewalld.lock
}

firewalld_zones() {
    { firewall-cmd --get-default-zone; firewall-cmd --get-active-zones | grep -v '^[[:space:]]'; } | sort -u | grep -vx trusted || true
}

firewalld_forget() {
    local zone
    firewall-cmd --permanent --info-service="$1" > /dev/null 2>&1 || return 0
    for zone in $(firewall-cmd --permanent --get-zones); do
        if firewall-cmd --permanent --zone="$zone" --query-service="$1" > /dev/null 2>&1; then
            firewall-cmd --permanent --zone="$zone" --remove-service="$1" > /dev/null
        fi
    done
    firewall-cmd --permanent --delete-service="$1" > /dev/null
    rm -f "/etc/firewalld/services/$1.xml.old"
}

firewalld_remove_service() {
    firewalld_forget "$1"
    firewall-cmd --reload > /dev/null
}

firewalld_define_service() {
    local service=$1 rule ports port zone
    shift
    firewalld_forget "$service"
    firewall-cmd --permanent --new-service="$service" > /dev/null
    for rule in "$@"; do
        ports=${rule%/*}
        for port in ${ports//,/ }; do
            firewall-cmd --permanent --service="$service" --add-port="${port/:/-}/${rule##*/}" > /dev/null
        done
    done
    for zone in $(firewalld_zones); do
        firewall-cmd --permanent --zone="$zone" --add-service="$service" > /dev/null
    done
    firewall-cmd --reload > /dev/null
}

firewalld_close() {
    firewalld_active || return 0
    firewalld_exclusively firewalld_remove_service "$1"
}

firewalld_open() {
    firewalld_active || return 0
    firewalld_exclusively firewalld_define_service "$@"
    echo "firewalld is on, opened ${*:2} for Databasify"
}

firewalld_trust_interface() {
    if [ "$(firewall-cmd --permanent --get-zone-of-interface="$1" 2> /dev/null || true)" = trusted ]; then
        return 0
    fi
    firewall-cmd --permanent --zone=trusted --change-interface="$1" > /dev/null
    firewall-cmd --reload > /dev/null
    echo "firewalld is on, trusted the $1 interface"
}

firewalld_distrust_interface() {
    if [ "$(firewall-cmd --permanent --get-zone-of-interface="$1" 2> /dev/null || true)" != trusted ]; then
        return 0
    fi
    firewall-cmd --permanent --zone=trusted --remove-interface="$1" > /dev/null
    firewall-cmd --reload > /dev/null
}

firewalld_trust() {
    firewalld_active || return 0
    firewalld_exclusively firewalld_trust_interface "$1"
}

firewalld_distrust() {
    firewalld_active || return 0
    firewalld_exclusively firewalld_distrust_interface "$1"
}
