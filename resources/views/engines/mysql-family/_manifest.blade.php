write_manifest() {
@if (($newClusterManifest ?? null) !== null)
    if [ -e "$ROOT/state/new-cluster" ]; then
        printf '%s' {!! $sh->arg($newClusterManifest) !!} | write_file "$ROOT/conf/processes.json" 600 false
        return
    fi
@endif
    printf '%s' {!! $sh->arg($files->manifest) !!} | write_file "$ROOT/conf/processes.json" 600 false
}
