@if ($readOnly)
databasify ctl stop mariadb-clone > /dev/null 2>&1 || true
databasify ctl reset-failed mariadb-clone > /dev/null 2>&1 || true
databasify ctl run --quiet --collect --unit=mariadb-clone sh -c 'mariadb-backup --defaults-extra-file={{ $configFile }} --backup --stream=xbstream --target-dir=/tmp 2>/dev/null | socat -u - TCP4-LISTEN:4444,bind={{ $donor ?? $primary }},range={{ $node }}/32'
echo "streaming a copy of the data to {{ $node }}"
@else
echo "{{ $node }} joins through Galera state transfer"
@endif
