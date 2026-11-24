@if ($fetch->source === \App\Enums\Databases\PackageSource::Repository)
if grep -rqsF {!! $sh->arg($fetch->repositoryAddress()) !!} /etc/apt/sources.list /etc/apt/sources.list.d; then
    fail_task "This repository is already one of the image's sources. Pick the PGDG option instead."
fi
KEY={!! $sh->arg($fetch->keyPath()) !!}
LIST={!! $sh->arg("/etc/apt/sources.list.d/databasify-{$fetch->run}.list") !!}
KEY_SOURCE={!! $sh->arg($fetch->repositoryKey ?? '') !!}
trap 'rm -f "$KEY" "$LIST"' EXIT
mkdir -p /etc/apt/keyrings
case "$KEY_SOURCE" in
    https://*) curl -fsSL --proto =https --proto-redir =https "$KEY_SOURCE" -o "$KEY" 2> "$ERRORS" || fail_task "Could not download the repository key: $(tail -n 1 "$ERRORS")" ;;
    *) printf '%s\n' "$KEY_SOURCE" > "$KEY" ;;
esac
printf '%s\n' {!! $sh->arg($fetch->signedSourceLine()) !!} > "$LIST"
@endif
apt-get update -qq > /dev/null 2> "$ERRORS" || fail_task "apt-get update failed: $(tail -n 3 "$ERRORS")"
