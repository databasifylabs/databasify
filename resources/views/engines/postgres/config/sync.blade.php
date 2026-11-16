@include('engines.postgres._psql')
patronictl_run edit-config --force --replace /srv/postgres/conf/dcs.yml > /dev/null
@if ($restartPrimary)
pending_restart() {
    [ "$(patroni_state | jq -r '.pending_restart // false')" = true ]
}

leading() {
    case "$(patroni_state | jq -r '(.role // "") + " " + (.state // "")')" in
        'primary running' | 'master running') return 0 ;;
        *) return 1 ;;
    esac
}

if ! wait_until 60 2 pending_restart; then
    echo "The settings need no restart."
    exit 0
fi
patronictl_run restart main --role primary --force > /dev/null
wait_until 300 2 leading || true
echo "Synced the settings and restarted the primary."
@else
echo "The settings need no restart."
@endif
