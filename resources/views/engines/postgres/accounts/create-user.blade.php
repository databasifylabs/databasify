SELECT NOT EXISTS (SELECT FROM pg_roles WHERE rolname = {!! $sql->string($name) !!}) AS missing \gset
\if :missing
CREATE ROLE {!! $sql->name($name) !!} LOGIN;
\endif
ALTER ROLE {!! $sql->name($name) !!} LOGIN PASSWORD {!! $sql->string($password) !!};
@include('engines.postgres.accounts._grants')
