SELECT 'CREATE ROLE "{!! $name !!}" LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{!! $name !!}') \gexec
ALTER ROLE "{!! $name !!}" LOGIN PASSWORD '{!! $password !!}';
@foreach ($databases as $database)
@include('ssh.engines.postgres.accounts.grant', ['database' => $database, 'user' => $name])
@endforeach
