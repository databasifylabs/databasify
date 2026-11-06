@include('ssh.databases.helpers')
databasify ctl stop {{ $flavor }}-migration > /dev/null 2>&1 || true
databasify ctl reset-failed {{ $flavor }}-migration > /dev/null 2>&1 || true
rm -rf "$dir/migration"
install -d -m 700 "$dir/migration"
(umask 077 && echo '{{ base64_encode($config) }}' | base64 -d > "$dir/migration/shake.toml")
databasify ctl run --quiet --remain-after-exit --unit={{ $flavor }}-migration --property=WorkingDirectory="$dir/migration" /usr/local/bin/redis-shake "$dir/migration/shake.toml"
echo "{{ $continuous ? 'syncing' : 'copying' }} from {{ $address }} in the background"
