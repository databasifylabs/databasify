@include('engines.mysql-family._mysql')
@include('engines.mariadb._galera')
become_writer_if_recorded {!! $sh->arg($ip) !!}
echo "Claimed the writer role if no one holds it."
