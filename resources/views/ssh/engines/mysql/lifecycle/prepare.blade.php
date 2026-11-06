@include('ssh.databases.helpers')
install -d -m 750 -o mysql -g mysql "$dir/data"
install -d -m 755 -o mysql -g mysql {{ $runDirectory }}
if [ ! -d "$dir/data/mysql" ]; then
    mysqld --defaults-file={{ $configFile }} --initialize-insecure --user=mysql --init-file="$dir/conf/init.sql" > /dev/null 2>&1
    echo "initialised MySQL {{ $line }} in $dir/data"
fi
node_marker="$dir/data/.databasify-node"
if [ -s "$node_marker" ] && [ "$(cat "$node_marker")" != '{{ $nodeKey }}' ] && [ -f "$dir/data/auto.cnf" ]; then
    rm -f "$dir/data/auto.cnf"
    echo "this data directory was copied from another node, mysqld gives it a new server_uuid"
fi
echo '{{ $nodeKey }}' > "$node_marker"
chown mysql:mysql "$node_marker"
