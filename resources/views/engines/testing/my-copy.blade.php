@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mariadb._remote')
@include('engines.mariadb._dump')
ROOT=$COPY_ROOT

if copy_from 10.0.0.1 25060 databasify_repl secret true true; then
    printf 'copied %s\n' "$COPIED_GTID"
else
    printf 'failed: %s\n' "$ATTEMPT_ERROR"
fi
