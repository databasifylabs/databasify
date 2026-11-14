set -euo pipefail
@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rclone')
BINLOGS="$ROOT/archive/binlogs"
MARK="$ROOT/state/binlog-rotated"
ROTATE_WAIT=30

local_binlogs() {
    local file
    for file in "$BINLOGS"/*; do
        if [ -f "$file" ] && [[ $file != *.tmp ]]; then
            printf '%s\n' "${file##*/}"
        fi
    done | sort
}

writing_binlog() {
    local file newest=''
    for file in "$BINLOGS"/*; do
        if [ -f "$file" ] && [[ $file != *.tmp ]] && { [ -z "$newest" ] || [ ! "$newest" -nt "$file" ]; }; then
            newest=$file
        fi
    done
    [ -n "$newest" ] || return 1
    printf '%s' "${newest##*/}"
}

open_binlog() {
    local name
    name=$(writing_binlog) || return 1
    printf '%s %s' "$name" "$(wc -c < "$BINLOGS/$name" | tr -d ' ')"
}

rotation_error=''
if open=$(open_binlog) && [ "$(cat "$MARK" 2> /dev/null || true)" != "$open" ]; then
    my_try '' <<< 'FLUSH BINARY LOGS'
    if [ -n "$MY_ERROR" ]; then
        rotation_error=$MY_ERROR_TEXT
    else
        deadline=$(( $(date +%s) + ROTATE_WAIT ))
        until next=$(open_binlog) && [ "$next" != "$open" ]; do
            if [ "$(date +%s)" -ge "$deadline" ]; then
                rotation_error='the binlog stream did not pick up the rotation'
                break
            fi
            sleep 1
        done
        if [ -z "$rotation_error" ]; then
            mkdir -p -m 700 "$ROOT/state"
            (umask 077; open_binlog > "$MARK.tmp")
            mv "$MARK.tmp" "$MARK"
        fi
    fi
fi

@if ($engine->id === 'mysql')
source_id=$(my '' <<'SQL'
@verbatim
SELECT @@server_uuid
@endverbatim
SQL
)
@else
source_id=$(cat "$ROOT/state/archive-id" 2> /dev/null || true)
if [ -z "$source_id" ]; then
    source_id=$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
    mkdir -p -m 700 "$ROOT/state"
    (umask 077; printf '%s' "$source_id" > "$ROOT/state/archive-id")
fi
@endif

writing=$(writing_binlog || true)
closed=$(local_binlogs | grep -vxF -- "$writing" || true)
for name in $closed; do
    rclone copyto "$BINLOGS/$name" "$(remote_of "binlogs/$source_id/$name")"
    rm -f "$BINLOGS/$name"
done

if [ -n "$rotation_error" ]; then
    fail_task "$rotation_error"
fi
