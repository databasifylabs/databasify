@include('ssh.databases.helpers')
databasify ctl stop {{ $flavor }}-migration > /dev/null 2>&1 || true
databasify ctl reset-failed {{ $flavor }}-migration > /dev/null 2>&1 || true
rm -rf "$dir/migration"
echo "migration stopped"
