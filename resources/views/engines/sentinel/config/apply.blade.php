@include('engines.sentinel._cli')
@include('engines._write')
originals=$(cat "$ROOT/state/originals.json" 2> /dev/null || true)
[ -n "$originals" ] || originals='{}'
remembered() {
    jq -e --arg name "$1" 'has($name)' <<< "$originals" > /dev/null
}
remember() {
    local reply
    remembered "$1" && return 0
    reply=$(local_cli CONFIG GET "$1")
    [ "$(head -n 1 <<< "$reply")" = "$1" ] || databasify_fail internal "$FLAVOR has no setting named $1"
    originals=$(jq -c --arg name "$1" --arg value "$(tail -n +2 <<< "$reply")" '. + {($name): $value}' <<< "$originals")
}
set_config() {
    cli_try local_cli CONFIG SET "$1" "$2"
    if [ -n "$CLI_ERROR" ]; then
        databasify_fail internal "could not set $1: $CLI_ERROR_TEXT"
    elif [ "$CLI_OUTPUT" != OK ]; then
        databasify_fail internal "could not set $1: $CLI_OUTPUT"
    fi
}
restore() {
    local value
    remembered "$1" || return 0
    value=$(jq -r --arg name "$1" '.[$name]' <<< "$originals")
    set_config "$1" "$value"
    originals=$(jq -c --arg name "$1" 'del(.[$name])' <<< "$originals")
}
save_originals() {
    printf '%s' "$originals" | write_file "$ROOT/state/originals.json" 600 true || true
}
@foreach ($desired as $name => $value)
remember {!! $sh->arg($name) !!}
@endforeach
save_originals
@foreach ($desired as $name => $value)
set_config {!! $sh->arg($name) !!} {!! $sh->arg($value) !!}
@endforeach
@foreach ($removed as $name)
restore {!! $sh->arg($name) !!}
@endforeach
save_originals
echo "Applied the online settings."
