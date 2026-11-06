CREATE USER IF NOT EXISTS '{!! $name !!}'@'%' IDENTIFIED {{ $encryption ? 'WITH '.$encryption->value.' ' : '' }}BY '{!! $password !!}';
@foreach ($databases as $database)
@include('ssh.engines.mysql.accounts.grant', ['database' => $database, 'user' => $name])
@endforeach
