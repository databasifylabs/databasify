@include('engines.sentinel._cli')
source_cli() {
    CLI_SECRET=$SOURCE_PASSWORD CLI_USER=$SOURCE_USER CLI_TLS=$SOURCE_TLS cli "$SOURCE_HOST" "$SOURCE_PORT" "$@"
}
cli_try source_cli INFO
if [ -n "$CLI_ERROR" ]; then
    result "$(jq -cn --arg error "$(head -n 1 <<< "$CLI_ERROR_TEXT")" '{error: $error}')"
    exit 0
fi
info=$CLI_OUTPUT
version=$(info_field "$info" valkey_version)
[ -n "$version" ] || version=$(info_field "$info" redis_version)
memory=$(info_field "$info" used_memory)
warnings='[]'
cli_try source_cli INFO modules
if [ -z "$CLI_ERROR" ]; then
    source_modules=$CLI_OUTPUT
    cli_try local_cli INFO modules
    local_modules=$CLI_OUTPUT
    available=$(sed -n 's/.*name=\([^,]*\),.*/\1/p' <<< "$local_modules")
    while IFS= read -r name; do
        [ -z "$name" ] && continue
        if ! grep -qxF -- "$name" <<< "$available"; then
            warnings=$(jq -c --arg name "$name" '. + ["the source loads the " + $name + " module, whose data types this server cannot store"]' <<< "$warnings")
        fi
    done < <(sed -n 's/.*name=\([^,]*\),.*/\1/p' <<< "$source_modules")
fi
size=null
[[ $memory =~ ^[0-9]+$ ]] && size=$(( memory / 1048576 ))
result "$(jq -cn --arg version "$version" --argjson size "$size" --argjson warnings "$warnings" '{error: null, version: (if $version == "" then null else $version end), sizeMb: $size, warnings: $warnings}')"
