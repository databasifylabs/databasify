@include('engines.sentinel._cli')
cli_try local_cli INFO replication
if [ "$CLI_ERROR" = unavailable ]; then
    result '{"role":"offline","version":null}'
    exit 0
elif [ -n "$CLI_ERROR" ]; then
    databasify_fail "$CLI_ERROR" "$CLI_ERROR_TEXT"
fi
replication=$CLI_OUTPUT
server=$(local_cli INFO server)
role=unknown
if [ "$(info_field "$replication" role)" = master ]; then
    role=primary
elif [ "$(info_field "$replication" role)" = slave ] && [ "$(info_field "$replication" master_link_status)" = up ] && [ "$(info_field "$replication" master_sync_in_progress)" = 0 ]; then
    role=replica
fi
version=$(info_field "$server" {!! $sh->arg($engine->versionField) !!})
result "$(jq -cn --arg role "$role" --arg version "$version" '{role: $role, version: (if $version == "" then null else $version end)}')"
