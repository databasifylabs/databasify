@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mariadb._remote')
@include('engines.mariadb._dump')
ROOT=$COPY_ROOT

copy_from 10.0.0.1 25060 databasify_repl secret true true
printf '%s\n' "$COPIED_GTID"
