pcsm_status() {
    curl -fsS --max-time 5 http://127.0.0.1:2242/status 2> /dev/null
}

pcsm_post() {
    local reply verdict
    reply=$(curl -fsS --max-time 30 -X POST -H 'Content-Type: application/json' -d '{}' "http://127.0.0.1:2242$1" 2>&1) \
        || databasify_fail internal "ClusterSync $1: $reply"
    verdict=$(json_eval "$reply" 'print(input && input.ok ? "ok" : String((input && input.error) || "no reply"))') || verdict='not JSON'
    if [ "$verdict" != ok ]; then
        databasify_fail internal "ClusterSync $1: $verdict"
    fi
}
