@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
target={{ explode(':', $target)[0] }}
msql "REPLACE INTO databasify.writer VALUES (1, '$target')"
msql "SET GLOBAL read_only = 1"
handed_over() { [ "$(chosen_writer)" = "$target" ]; }
wait_for 120 handed_over
echo "writer moved to $target"
