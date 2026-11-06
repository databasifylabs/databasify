@include('ssh.databases.helpers')
state={{ $migrationStateDir }}
databasify ctl stop {{ $migrationUnit }} > /dev/null 2>&1 || true
databasify ctl reset-failed {{ $migrationUnit }} > /dev/null 2>&1 || true
rm -rf "$state"
install -d -m 700 "$state" "$state/logs"
(umask 077 && echo '{{ base64_encode($config) }}' | base64 -d > "$state/collector.conf")
databasify ctl run --quiet --remain-after-exit --unit={{ $migrationUnit }} --property=WorkingDirectory="$state" /usr/local/bin/mongoshake -conf="$state/collector.conf" -verbose=0
echo "{{ $continuous ? 'syncing' : 'copying' }} from {{ $address }} in the background"
