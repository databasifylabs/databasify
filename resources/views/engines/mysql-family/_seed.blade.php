require_saved
if ! saved_is 'if .clustered then ((.readOnly | not) and (.primary == null or .primary == .ip)) else true end'; then
    result '{"seed":false}'
    exit 0
fi
replication=$(my_string "$REPLICATION_PASSWORD")
agent=$(my_string "$AGENT_PASSWORD")
