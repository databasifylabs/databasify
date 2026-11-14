@include('engines.sentinel._cli')
@include('engines._write')
unit="$FLAVOR-migration"
databasify ctl stop "$unit" > /dev/null 2>&1 || true
databasify ctl reset-failed "$unit" > /dev/null 2>&1 || true
rm -rf "$ROOT/migration"
mkdir -p -m 700 "$ROOT/migration"
printf '%s' "$SHAKE" | base64 -d | write_file "$ROOT/migration/shake.toml" 600 true || true
databasify ctl run -u "$unit" -r -p "WorkingDirectory=$ROOT/migration" -- /usr/local/bin/redis-shake "$ROOT/migration/shake.toml" > /dev/null 2>&1 \
    || databasify_fail internal "could not start $unit"
echo "Started the migration copy."
