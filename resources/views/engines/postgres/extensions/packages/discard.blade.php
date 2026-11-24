@include('engines._runtime')
rm -rf {!! $sh->arg("{$directory}/incoming/{$run}") !!} {!! $sh->arg("{$directory}/incoming/{$run}.err") !!}
