@include('engines.postgres.accounts._grants')
@if ($replication)
ALTER ROLE {!! $sql->name($name) !!} REPLICATION;
@endif
