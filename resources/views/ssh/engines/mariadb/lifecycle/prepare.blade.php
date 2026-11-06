@include('ssh.databases.helpers')
install -d -m 750 -o mysql -g mysql "$dir/data"
@if ($fresh && $clustered && ! $seed && ! $readOnly)
rm -f "$dir/data/gvwstate.dat"
@endif
@if ($seed && ($fresh || ! $clustered))
if [ ! -d "$dir/data/mysql" ]; then
    mariadb-install-db --defaults-extra-file={{ $configFile }} --user=mysql --datadir="$dir/data" --auth-root-authentication-method=socket --skip-test-db > /dev/null
    chown -R mysql:mysql "$dir/data"
    echo "initialised MariaDB {{ $line }} in $dir/data"
fi
@endif
