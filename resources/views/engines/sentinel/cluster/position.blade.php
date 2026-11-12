@include('engines.sentinel._cli')
@include('engines.sentinel._node')
position=-1
if unit_active "$FLAVOR-server"; then
    info=$(replication_info || true)
    offset=$(info_field "$info" master_repl_offset)
    [[ $offset =~ ^[0-9]+$ ]] && position=$offset
fi
if [ "$position" -le 0 ]; then
    stored=$(timeout 240 {!! $sh->arg($engine->checkRdb) !!} "$ROOT/data/dump.rdb" 2>&1 | sed -n "s/.*AUX FIELD repl-offset = '\([0-9]*\)'.*/\1/p" | head -n 1 || true)
    if [ -n "$stored" ] && [ "$stored" -gt "$position" ]; then
        position=$stored
    fi
fi
if [ "$position" -lt 0 ] && has_data; then
    position=0
fi
result "{\"position\":$position}"
