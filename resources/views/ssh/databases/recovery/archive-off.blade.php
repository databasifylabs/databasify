@if (! ($archiveEnabled ?? false) && isset($archiveWork))
if [ -d {{ $archiveWork }}/bin ]; then
    rm -rf {{ $archiveSecrets }} {{ $archiveWork }}/bin {{ $archiveWork }}/state {{ $archiveWork }}/tmp
    echo "turned off point-in-time recovery archiving"
fi
if [ -d {{ $archiveWork }} ] && [ -z "$(ls -A {{ $archiveWork }})" ]; then
    rmdir {{ $archiveWork }}
fi
@endif
