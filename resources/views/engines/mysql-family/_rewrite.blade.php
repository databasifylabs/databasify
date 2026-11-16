@include('engines.mysql-family._manifest')

write_config_files() {
    CONFIG_CHANGED=false
    printf '%s' {!! $sh->arg($requestJson) !!} | write_file "$ROOT/state/node.json" 600 false || true
@foreach ($files->files as $file)
    if printf '%s' {!! $sh->arg($file->content) !!} | write_file {!! $sh->arg($file->path) !!} {{ decoct($file->mode) }} {{ $file->path === $engine->home.'/conf/my.cnf' ? 'true' : 'false' }}; then
        CONFIG_CHANGED=true
    fi
@endforeach
    if write_manifest; then
        CONFIG_CHANGED=true
    fi
}
