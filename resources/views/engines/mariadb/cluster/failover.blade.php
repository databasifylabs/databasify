@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mariadb._remote')
REPLICATION_SECRET={!! $sh->arg((string) $saved->secrets->replication) !!}
[ -n "$REPLICATION_SECRET" ] || databasify_fail invalid_request 'MariaDB clusters need the replication secret'
TARGET={!! $sh->arg($target) !!}
target_cnf=$(remote_cnf "$TARGET" "$REPLICATION_SECRET")
MY_DEFAULTS=$target_cnf my '' > /dev/null <<SQL
CREATE DATABASE IF NOT EXISTS databasify;
CREATE TABLE IF NOT EXISTS databasify.writer (id TINYINT PRIMARY KEY, ip VARCHAR(45) NOT NULL);
REPLACE INTO databasify.writer VALUES (1, {!! $sql->string($target) !!})
SQL
for member in {!! collect($saved->members)->filter(fn ($member): bool => $member->voting && $member->ip !== $target)->map(fn ($member): string => $sh->arg($member->ip))->implode(' ') !!}; do
    member_cnf=$(remote_cnf "$member" "$REPLICATION_SECRET")
    MY_DEFAULTS=$member_cnf my_try '' <<< 'SET GLOBAL read_only = ON'
    rm -f "$member_cnf"
    if [ -n "$MY_ERROR" ] && [ "$MY_ERROR" != unavailable ]; then
        rm -f "$target_cnf"
        databasify_fail "$MY_ERROR" "$MY_ERROR_TEXT"
    fi
done
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
