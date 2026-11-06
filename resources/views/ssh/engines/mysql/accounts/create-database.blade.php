CREATE DATABASE IF NOT EXISTS `{!! $name !!}`;
@foreach ($users as $user)
@include('ssh.engines.mysql.accounts.grant', ['database' => $name, 'user' => $user])
@endforeach
