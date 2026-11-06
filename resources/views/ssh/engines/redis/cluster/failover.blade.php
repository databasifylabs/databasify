@include('ssh.engines.redis.helpers')
@include('ssh.databases.helpers')
old=$(scli SENTINEL get-master-addr-by-name main | head -n 1)
scli SENTINEL FAILOVER main
moved() { current=$(scli SENTINEL get-master-addr-by-name main | head -n 1); [ -n "$current" ] && [ "$current" != "$old" ]; }
demoted() { vcli INFO replication | grep -q '^role:slave'; }
wait_for 120 moved
wait_for 120 demoted
settled() { grep -q '^master_link_status:up' <<< "$(vcli INFO replication)" && aof_written; }
wait_for 300 settled || echo "still syncing from the new primary"
echo "primary moved from $old to $(scli SENTINEL get-master-addr-by-name main | head -n 1)"
