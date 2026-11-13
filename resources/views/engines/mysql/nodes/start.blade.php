@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
unit_do start mysql-server
if [ -f "$ROOT/state/node.json" ] && jq -e '.archive != null' "$ROOT/state/node.json" > /dev/null; then
    unit_do start mysql-binlog-stream
fi
echo "Started MySQL."
