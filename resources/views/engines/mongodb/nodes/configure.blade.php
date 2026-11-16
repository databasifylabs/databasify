@include('engines.mongodb._mongo')
@include('engines._write')
engine_directory conf
engine_directory data
engine_directory state
@if ($tunedJson !== null)
if [ ! -f "$ROOT/state/tuned.json" ]; then
    printf '%s' {!! $sh->arg($tunedJson) !!} | write_file "$ROOT/state/tuned.json" 600 false || true
fi
@endif
changed=false
@foreach ($files as $file)
if printf '%s' {!! $sh->arg($file->content) !!} | write_file {!! $sh->arg($file->path) !!} {{ decoct($file->mode) }} {{ in_array($file->path, $owned, true) ? 'true' : 'false' }}; then
    changed=true
fi
@endforeach
if printf '%s' {!! $sh->arg($manifest) !!} | write_file "$ROOT/conf/processes.json" 600 false; then
    changed=true
fi
printf '%s' {!! $sh->arg($requestJson) !!} | write_file "$ROOT/state/node.json" 600 false || true
unit_do daemon-reload
@if ($agent)
if unit_active mongod; then
    unit_do start pbm-agent
fi
@endif
if [ "$changed" = true ]; then
    echo 'Wrote the MongoDB configuration.'
else
    echo 'The MongoDB configuration was already current.'
fi
