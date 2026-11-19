@include('ssh.backups.connection')
PROBE="$REMOTE/.check-{{ $probe }}"
printf '%s' '{{ $probe }}' > "$WORK/probe"
cleanup_probe() {
    transfer deletefile "$PROBE" >/dev/null 2>&1 || true
    cleanup_file_backup
}
trap cleanup_probe EXIT
transfer copyto "$WORK/probe" "$PROBE"
transfer cat "$PROBE" > "$WORK/read"
cmp "$WORK/probe" "$WORK/read"
transfer deletefile "$PROBE"
trap cleanup_file_backup EXIT
echo 'File storage write/read/delete check passed.'
