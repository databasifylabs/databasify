@include('engines.sentinel._cli')
source_cli() {
    CLI_SECRET=$SOURCE_PASSWORD CLI_USER=$SOURCE_USER CLI_TLS=$SOURCE_TLS cli "$SOURCE_HOST" "$SOURCE_PORT" "$@"
}
cli_try source_cli INFO
if [ -n "$CLI_ERROR" ]; then
    output=$(jq -cn --arg error "$(head -n 1 <<< "$CLI_ERROR_TEXT")" '{error: $error}')
    result "$output"
    exit 0
fi
info=$CLI_OUTPUT
modules=$(local_cli INFO modules)
output=$(jq -cn --arg info "$info" --arg modules "$modules" '{error: null, info: $info, modules: $modules}')
result "$output"
