@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rewrite')

for directory in conf data run state; do
    engine_directory "$directory"
done
@if ($tunedJson !== null)
if [ ! -f "$ROOT/state/tuned.json" ]; then
    printf '%s' {!! $sh->arg($tunedJson) !!} | write_file "$ROOT/state/tuned.json" 600 false || true
fi
@endif

@if ($engine->id === 'mysql')
engine_directory conf/conf.d
@if ($groupedBoot)
if [ -e "$ROOT/state/grouped" ]; then
    printf '[mysqld]\nsuper_read_only=ON\n' | write_file "$ROOT/conf/conf.d/grouped.cnf" 600 true || true
fi
@endif
@endif

write_config_files
changed=$CONFIG_CHANGED

if [ -z "$(ls -A "$ROOT/data")" ]; then
    printf '%s' {!! $sh->arg($initSql) !!} | write_file "$ROOT/conf/init.sql" 600 true || true
    rm -f "$ROOT/state/archive-id"
    output=$({!! collect($initCommand)->map(fn (string $part): string => $sh->arg($part))->implode(' ') !!} 2>&1) || {
        rm -f "$ROOT/conf/init.sql"
        databasify_fail internal {!! $sh->arg($initCommand[0].': ') !!}"$(tail -n 5 <<< "$output")"
    }
    rm -f "$ROOT/conf/init.sql"
@if ($seed)
    : | write_file "$ROOT/state/new-cluster" 600 false || true
    write_manifest || true
@endif
    changed=true
fi

unit_do daemon-reload
@if ($helpers !== [])
unit_do start {!! collect($helpers)->map(fn (string $helper): string => $sh->arg($helper))->implode(' ') !!}
@endif
[ "$changed" = true ] && echo "Wrote the new configuration." || echo "The configuration was already up to date."
