umask 077
command -v rclone >/dev/null || { echo 'Install rclone on the database server before using file backups.' >&2; exit 1; }
WORK=$(mktemp -d /tmp/databasify-backup.XXXXXXXX)
cleanup_file_backup() { rm -rf -- "$WORK"; }
trap cleanup_file_backup EXIT
export RCLONE_CONFIG="$WORK/rclone.conf"
touch "$RCLONE_CONFIG"
export RCLONE_CONFIG_TARGET_TYPE={!! $sh->arg($destination->protocol === 'sftp' ? 'sftp' : 'ftp') !!}
export RCLONE_CONFIG_TARGET_HOST={!! $sh->arg($destination->host) !!}
export RCLONE_CONFIG_TARGET_PORT={!! $sh->arg((string) $destination->port) !!}
export RCLONE_CONFIG_TARGET_USER={!! $sh->arg($destination->username) !!}
export RCLONE_CONFIG_TARGET_PASS
RCLONE_CONFIG_TARGET_PASS=$(printf '%s' {!! $sh->arg($destination->credentials['password'] ?? '') !!} | rclone obscure -)
@if ($destination->protocol === 'sftp')
printf '%s\n' {!! $sh->arg(($destination->port === 22 ? $destination->host : '['.$destination->host.']:'.$destination->port).' '.$destination->host_key) !!} > "$WORK/known_hosts"
export RCLONE_CONFIG_TARGET_KNOWN_HOSTS_FILE="$WORK/known_hosts"
@elseif ($destination->protocol === 'ftps')
export RCLONE_CONFIG_TARGET_EXPLICIT_TLS=true
export RCLONE_CONFIG_TARGET_NO_CHECK_CERTIFICATE=false
@endif
REMOTE={!! $sh->arg('target:'.rtrim($destination->path, '/').'/databasify/'.$destination->id) !!}
transfer() { rclone --contimeout 15s --timeout 2m --retries 2 --low-level-retries 2 "$@"; }
