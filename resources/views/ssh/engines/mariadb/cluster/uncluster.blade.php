@include('ssh.engines.mariadb.helpers')
@include('ssh.databases.helpers')
if in_galera; then
    databasify ctl restart mariadb
else
    restart_unless_current
fi
up() { msql "SELECT 1" > /dev/null; }
wait_for 300 up
msql "SET GLOBAL read_only = 0"
rm -f "$dir"/data/galera.cache "$dir"/data/grastate.dat "$dir"/data/gvwstate.dat "$dir"/data/mariadb_backup_galera_info_SST "$dir"/data/mariadb_backup_galera_info "$dir"/data/donor_galera_info
echo "mariadb runs on its own on this node"
