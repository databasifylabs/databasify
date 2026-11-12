@include('engines.mysql-family._mysql')
my_try '' <<'SQL'
@verbatim
SELECT @@gtid_executed
@endverbatim
SQL
if [ -n "$MY_ERROR" ]; then
    result '{"executed":null}'
else
    result "$(jq -cn --arg executed "$MY_OUTPUT" '{executed: $executed}')"
fi
