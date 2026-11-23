@if ($fetch !== null)
@include('engines.postgres.extensions.packages.fetch')
if compgen -G "$DIR/*.deb" > /dev/null; then
    apt-get install -y -qq --no-install-recommends -o Dir::Etc::SourceList=/dev/null -o Dir::Etc::SourceParts=/dev/null "$DIR"/*.deb > /dev/null
fi
@else
apt-get install -y -qq --no-install-recommends -o Dir::Etc::SourceList=/dev/null -o Dir::Etc::SourceParts=/dev/null {!! implode(' ', array_map(fn (string $fileName): string => $sh->arg("{$directory}/{$fileName}"), $fileNames)) !!} > /dev/null
@endif
