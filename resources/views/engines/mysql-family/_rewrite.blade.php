write_manifest() {
    local manifest=$MANIFEST
    if [ -n "${MANIFEST_NEW_CLUSTER:-}" ] && [ -e "$ROOT/state/new-cluster" ]; then
        manifest=$MANIFEST_NEW_CLUSTER
    fi
    printf '%s' "$manifest" | base64 -d | write_file "$ROOT/conf/processes.json" 600 false
}

write_config_files() {
    local path mode content owned
    CONFIG_CHANGED=false
    printf '%s' "$REQUEST_JSON" | write_file "$ROOT/state/node.json" 600 false || true
    while IFS=$'\t' read -r path mode content; do
        [ -z "$path" ] && continue
        owned=false
        if [ "$path" = "$ROOT/conf/my.cnf" ]; then
            owned=true
        fi
        if printf '%s' "$content" | base64 -d | write_file "$path" "$mode" "$owned"; then
            CONFIG_CHANGED=true
        fi
    done < <(jq -r '.[] | [.path, .mode, .content] | @tsv' <<< "$FILES")
    if write_manifest; then
        CONFIG_CHANGED=true
    fi
}
