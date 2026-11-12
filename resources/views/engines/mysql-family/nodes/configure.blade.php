@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')

for directory in conf data run state; do
    engine_directory "$directory"
done
if [ -n "$TUNED_JSON" ] && [ ! -f "$ROOT/state/tuned.json" ]; then
    printf '%s' "$TUNED_JSON" | write_file "$ROOT/state/tuned.json" 600 false || true
fi
printf '%s' "$REQUEST_JSON" | write_file "$ROOT/state/node.json" 600 false || true

@if ($engine->id === 'mysql')
engine_directory conf/conf.d
if [ "$GROUPED_BOOT" = true ] && [ -e "$ROOT/state/grouped" ]; then
    printf '[mysqld]\nsuper_read_only=ON\n' | write_file "$ROOT/conf/conf.d/grouped.cnf" 600 true || true
fi
@endif

changed=false
while IFS=$'\t' read -r path mode content; do
    [ -z "$path" ] && continue
    owned=false
    if [ "$path" = "$ROOT/conf/my.cnf" ]; then
        owned=true
    fi
    if printf '%s' "$content" | base64 -d | write_file "$path" "$mode" "$owned"; then
        changed=true
    fi
done < <(jq -r '.[] | [.path, .mode, .content] | @tsv' <<< "$FILES")

write_manifest() {
    local manifest=$MANIFEST
    if [ -n "$MANIFEST_NEW_CLUSTER" ] && [ -e "$ROOT/state/new-cluster" ]; then
        manifest=$MANIFEST_NEW_CLUSTER
    fi
    printf '%s' "$manifest" | base64 -d | write_file "$ROOT/conf/processes.json" 600 false
}

if write_manifest; then
    changed=true
fi

if [ -z "$(ls -A "$ROOT/data")" ]; then
    printf '%s' "$INIT_SQL" | write_file "$ROOT/conf/init.sql" 600 true || true
    rm -f "$ROOT/state/archive-id"
    read -r -a init <<< "$INIT_COMMAND"
    output=$("${init[@]}" 2>&1) || {
        rm -f "$ROOT/conf/init.sql"
        databasify_fail internal "${init[0]}: $(tail -n 5 <<< "$output")"
    }
    rm -f "$ROOT/conf/init.sql"
    if [ "$SEED" = true ]; then
        : | write_file "$ROOT/state/new-cluster" 600 false || true
        write_manifest || true
    fi
    changed=true
fi

printf '{}' | databasify service reload > /dev/null
if [ -n "$HELPERS" ]; then
    read -r -a helpers <<< "$HELPERS"
    unit_do start "${helpers[@]}"
fi
result "$(jq -cn --argjson changed "$changed" '{changed: $changed}')"
