@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
target={{ explode(':', $target)[0] }}
patroni_ctl switchover --candidate "$target" --force > /dev/null
demoted() { [ "$(own_role)" = "replica" ]; }
wait_for 120 demoted
echo "primary moved to $target"
