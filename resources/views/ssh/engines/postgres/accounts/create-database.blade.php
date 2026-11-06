SELECT 'CREATE DATABASE "{!! $name !!}"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{!! $name !!}') \gexec
@foreach ($users as $user)
@include('ssh.engines.postgres.accounts.grant', ['database' => $name, 'user' => $user])
@endforeach
