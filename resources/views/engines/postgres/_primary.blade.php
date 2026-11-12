recovery=$(pg postgres <<'SQL'
SELECT pg_is_in_recovery()
SQL
)
if [ "$recovery" = t ]; then
    databasify_fail not_primary 'this node is a replica; run the command on the primary'
fi
