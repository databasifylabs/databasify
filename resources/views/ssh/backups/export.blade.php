@include('ssh.backups.connection')
command -v gpg >/dev/null || { echo 'Install gnupg on the database server before using encrypted file backups.' >&2; exit 1; }
CONTAINER={!! $sh->arg($container) !!}
printf '%s' {!! $sh->arg($destination->credentials['encryption_password'] ?? '') !!} > "$WORK/passphrase"
@if ($engine === 'postgres')
docker exec "$CONTAINER" env PGHOST=/srv/postgres/run PGPORT={{ $port }} HOME=/var/lib/postgresql USER=postgres \
    setpriv --reuid=64999 --regid=64999 --clear-groups /usr/lib/postgresql/{{ $line }}/bin/pg_dumpall > "$WORK/export.sql"
@elseif ($engine === 'mysql')
docker exec "$CONTAINER" mysqldump --defaults-extra-file=/srv/mysql/conf/admin.cnf --all-databases --single-transaction --routines --events --triggers --hex-blob --no-tablespaces > "$WORK/export.sql"
@elseif ($engine === 'mariadb')
docker exec "$CONTAINER" mariadb-dump --defaults-extra-file=/srv/mariadb/conf/admin.cnf --all-databases --single-transaction --routines --events --triggers --hex-blob > "$WORK/export.sql"
@endif
test -s "$WORK/export.sql"
gzip "$WORK/export.sql"
mkdir -m 700 "$WORK/gnupg"
gpg --homedir "$WORK/gnupg" --batch --yes --pinentry-mode loopback --passphrase-file "$WORK/passphrase" \
    --symmetric --cipher-algo AES256 --output "$WORK/backup.sql.gz.gpg" "$WORK/export.sql.gz" 2>/dev/null
FILE={!! $sh->arg($filename) !!}
HASH=$(sha256sum "$WORK/backup.sql.gz.gpg" | cut -d' ' -f1)
transfer copyto "$WORK/backup.sql.gz.gpg" "$REMOTE/$FILE.part"
REMOTE_HASH=$(transfer cat "$REMOTE/$FILE.part" | sha256sum | cut -d' ' -f1)
test "$HASH" = "$REMOTE_HASH" || { echo 'Uploaded backup checksum differs.' >&2; exit 1; }
transfer moveto "$REMOTE/$FILE.part" "$REMOTE/$FILE"
printf '%s  %s\n' "$HASH" "$FILE" > "$WORK/checksum"
transfer copyto "$WORK/checksum" "$REMOTE/$FILE.sha256"
printf '%s' {!! $sh->arg($manifest) !!} > "$WORK/manifest.json"
transfer copyto "$WORK/manifest.json" "$REMOTE/$FILE.complete.json"
echo 'Encrypted export uploaded and read-back checksum verified. A restore has not been tested.'
