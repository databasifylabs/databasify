@if ($file->keepExisting)
[ -s '{{ $file->path }}' ] || echo '{{ base64_encode($file->content) }}' | base64 -d | put_file '{{ $file->path }}' {{ $file->mode }} {!! $owner !!}
@else
echo '{{ base64_encode($file->content) }}' | base64 -d | put_file '{{ $file->path }}' {{ $file->mode }} {!! $owner !!}
@endif
