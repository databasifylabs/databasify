ufw_active() {
    command -v ufw > /dev/null 2>&1 && ufw status 2> /dev/null | grep -q '^Status: active'
}

ufw_close() {
    ufw_active || return 0
    ufw status numbered | { grep -E "# $1[[:space:]]*$" || true; } | sed -E 's/^\[ *([0-9]+)\].*/\1/' | sort -rn | while read -r rule; do
        ufw --force delete "$rule" > /dev/null
    done
}

ufw_open() {
    local comment=$1
    shift
    ufw_active || return 0
    ufw_close "$comment"
    for rule in "$@"; do
        ufw allow $rule comment "$comment" > /dev/null
    done
    echo "ufw is on, opened $* for Databasify"
}
