for file in {!! implode(' ', array_map($sh->arg(...), $fileNames)) !!}; do
    [ "$(sha256sum 2> /dev/null < {!! $sh->arg($directory) !!}/"$file" | cut -d ' ' -f 1)" = "${file%.*}" ] || echo "$file"
done
