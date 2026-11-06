container_running() {
    [ "$(docker inspect -f '@{{.State.Running}}' "$1" 2> /dev/null)" = true ]
}
running_image() {
    { docker inspect -f '@{{range .Config.Env}}@{{println .}}@{{end}}' "$1" 2> /dev/null || true; } | sed -n 's/^DATABASIFY_IMAGE=//p'
}
ctl() {
    docker exec {{ $container }} databasify ctl "$@"
}
image_ids() {
    docker run --rm --network none --entrypoint sh "$1" -c 'echo "$(id -u "$1"):$(id -g "$1")"' sh "$2"
}
use_engine_ids() {
    engine_uid=${1%:*}
    engine_gid=${1#*:}
}
