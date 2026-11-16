@include('engines._runtime')
unit_do restart {!! $sh->arg($engine->serverUnit()) !!}
echo "Restarted the server."
