SELECT NOT EXISTS (SELECT FROM pg_database WHERE datname = {!! $sql->string($name) !!}) AS missing \gset
\if :missing
CREATE DATABASE {!! $sql->name($name) !!};
\endif
