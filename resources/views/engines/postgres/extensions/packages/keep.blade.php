@include('engines._runtime')
set -euo pipefail
keep=(@foreach ($fileNames as $fileName){!! $sh->arg($fileName) !!} @endforeach)
for file in {!! $sh->arg($directory) !!}/*.deb; do
    [ -e "$file" ] || continue
    wanted=false
    for kept in ${keep[@]+"${keep[@]}"}; do
        [ "$kept" = "$(basename "$file")" ] && wanted=true
    done
    "$wanted" || rm -f "$file"
done
