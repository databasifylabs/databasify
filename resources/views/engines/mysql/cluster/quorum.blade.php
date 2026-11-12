@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
my_try '' <<'SQL'
SELECT COUNT(*), COALESCE(SUM(MEMBER_STATE = 'ONLINE'), 0) FROM performance_schema.replication_group_members
SQL
if [ -n "$MY_ERROR" ] || [ -z "$MY_OUTPUT" ]; then
    result '{"total":0,"online":0}'
    exit 0
fi
IFS=$'\t' read -r total online <<< "$MY_OUTPUT"
if [ -f "$ROOT/state/node.json" ]; then
    voters=$(jq '[.members[] | select(.voting)] | length' "$ROOT/state/node.json")
    if [ "$voters" -gt "$total" ]; then
        total=$voters
    fi
fi
my_try '' <<'SQL'
@verbatim
SELECT MEMBER_STATE FROM performance_schema.replication_group_members WHERE MEMBER_ID = @@server_uuid
@endverbatim
SQL
if [ "$MY_OUTPUT" != ONLINE ]; then
    online=0
fi
result "$(jq -cn --argjson total "$total" --argjson online "$online" '{total: $total, online: $online}')"
