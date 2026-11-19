umask 077
command -v rclone >/dev/null || { echo 'Install rclone on the database server before using file backups.' >&2; exit 1; }
WORK=$(mktemp -d /tmp/databasify-backup.XXXXXXXX)
cleanup_file_backup() { rm -rf -- "$WORK"; }
trap cleanup_file_backup EXIT
export RCLONE_CONFIG="$WORK/rclone.conf"
touch "$RCLONE_CONFIG"
TRANSFER_SECURITY_FLAGS=()
export RCLONE_CONFIG_TARGET_TYPE={!! $sh->arg($destination->protocol === 'sftp' ? 'sftp' : 'ftp') !!}
export RCLONE_CONFIG_TARGET_HOST={!! $sh->arg($destination->host) !!}
export RCLONE_CONFIG_TARGET_PORT={!! $sh->arg((string) $destination->port) !!}
export RCLONE_CONFIG_TARGET_USER={!! $sh->arg($destination->username) !!}
export RCLONE_CONFIG_TARGET_PASS
RCLONE_CONFIG_TARGET_PASS=$(printf '%s' {!! $sh->arg($destination->credentials['password'] ?? '') !!} | rclone obscure -)
@if ($destination->protocol === 'sftp')
if ! rclone help flags | grep -- '--sftp-host-key-algorithms' >/dev/null; then
    echo 'Update rclone: this SFTP backup requires --sftp-host-key-algorithms support.' >&2
    exit 1
fi
printf '%s\n' {!! $sh->arg(($destination->port === 22 ? $destination->host : '['.$destination->host.']:'.$destination->port).' '.$destination->host_key) !!} > "$WORK/known_hosts"
export RCLONE_CONFIG_TARGET_KNOWN_HOSTS_FILE="$WORK/known_hosts"
KEY_ALGORITHMS={!! $sh->arg(str_starts_with($destination->host_key ?? '', 'ssh-rsa ') ? 'rsa-sha2-512 rsa-sha2-256' : explode(' ', $destination->host_key ?? '')[0]) !!}
TRANSFER_SECURITY_FLAGS=(--sftp-known-hosts-file "$WORK/known_hosts" --sftp-host-key-algorithms "$KEY_ALGORITHMS")
@elseif ($destination->protocol === 'ftps')
export RCLONE_CONFIG_TARGET_EXPLICIT_TLS=true
export RCLONE_CONFIG_TARGET_NO_CHECK_CERTIFICATE=false
TRANSFER_SECURITY_FLAGS=(--ftp-explicit-tls --ftp-no-check-certificate=false)
@endif
REMOTE={!! $sh->arg('target:'.rtrim($destination->path, '/').'/databasify/'.$destination->id) !!}
transfer() { rclone --contimeout 15s --timeout 2m --retries 2 --low-level-retries 2 "${TRANSFER_SECURITY_FLAGS[@]}" "$@"; }
