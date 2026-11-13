@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mariadb._remote')
require_saved
replication_password
target_cnf=$(remote_cnf "$TARGET" "$REPLICATION_SECRET")
MY_DEFAULTS=$target_cnf my '' > /dev/null <<SQL
CREATE DATABASE IF NOT EXISTS databasify;
CREATE TABLE IF NOT EXISTS databasify.writer (id TINYINT PRIMARY KEY, ip VARCHAR(45) NOT NULL);
REPLACE INTO databasify.writer VALUES (1, $(my_string "$TARGET"))
SQL
while IFS= read -r member; do
    [ -z "$member" ] && continue
    member_cnf=$(remote_cnf "$member" "$REPLICATION_SECRET")
    MY_DEFAULTS=$member_cnf my_try '' <<< 'SET GLOBAL read_only = ON'
    rm -f "$member_cnf"
    if [ -n "$MY_ERROR" ] && [ "$MY_ERROR" != unavailable ]; then
        rm -f "$target_cnf"
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
done < <(jq -r --arg target "$TARGET" '.members[] | select(.voting and .ip != $target) | .ip' "$ROOT/state/node.json")
MY_DEFAULTS=$target_cnf my '' > /dev/null <<< 'SET GLOBAL read_only = OFF'

writable() {
    local value
    value=$(MY_DEFAULTS=$target_cnf my '' <<'SQL'
@verbatim
SELECT @@read_only
@endverbatim
SQL
) || return 1
    [ "$value" = 0 ] || [ "$(tr '[:lower:]' '[:upper:]' <<< "$value")" = OFF ]
}

wait_until 60 2 writable || {
    rm -f "$target_cnf"
    databasify_fail internal 'the server was not ready within 1m0s'
}
rm -f "$target_cnf"
echo "Handed the primary over to $TARGET."
