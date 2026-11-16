@include('engines._runtime')
unit_do start {!! $sh->arg($engine->serverUnit()) !!}
@if ($helpers !== [])
unit_do start {!! collect($helpers)->map(fn (string $helper): string => $sh->arg($helper))->implode(' ') !!}
@endif
echo "Started {{ $engine->label }}."
