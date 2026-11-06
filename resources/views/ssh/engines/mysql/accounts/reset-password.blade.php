ALTER USER '{!! $name !!}'@'%' IDENTIFIED {{ $encryption ? 'WITH '.$encryption->value.' ' : '' }}BY '{!! $password !!}';
