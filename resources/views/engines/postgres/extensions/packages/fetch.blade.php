@include('engines._runtime')
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
LINE={!! $sh->arg($fetch->line) !!}
DIR={!! $sh->arg("{$directory}/incoming/{$fetch->run}") !!}
ERRORS="$DIR.err"
mkdir -p "$DIR/partial"
declare -A installed
while IFS=$'\t' read -r status package depends; do
    case "$status" in ii*) installed[$package]=$depends ;; esac
done < <(dpkg-query -W -f='${db:Status-Abbrev}\t${Package}\t${Depends}, ${Pre-Depends}\n')
is_installed() {
    [ -n "${installed[$1]+set}" ]
}
names_in() {
    sed -E 's/\([^)]*\)//g; s/\[[^]]*\]//g' <<< "$1" | tr ',|' '\n\n' | sed -E 's/[[:space:]]//g; s/:[a-z0-9]+$//' | grep -v '^$' || true
}
replacing=({!! implode(' ', array_map($sh->arg(...), $fetch->replacing)) !!})
is_replacing() {
    local replaced_name
    for replaced_name in ${replacing[@]+"${replacing[@]}"}; do
        [ "$replaced_name" = "$1" ] && return 0
    done
    return 1
}
already_installed() {
    result "$(jq -cn --arg refusal "$1" '{packages: [], files: [], extensions: [], requires: [], alreadyInstalled: true, refusal: $refusal}')"
    exit 0
}
@if ($fetch->source !== \App\Enums\Databases\PackageSource::Upload && $fetch->replacing === [])
if is_installed {!! $sh->arg($fetch->package ?? '') !!}; then
    already_installed {!! $sh->arg(($fetch->package ?? '').' is already installed.') !!}
fi
@endif
@include('engines.postgres.extensions.packages._repository')
@if ($fetch->source === \App\Enums\Databases\PackageSource::Upload)
ARCH=$(dpkg --print-architecture)
targets=()
for file in "$DIR"/*.deb; do
    [ -e "$file" ] || continue
    built=$(dpkg-deb -f "$file" Architecture)
    case "$built" in
        "$ARCH"|all) targets+=("$file") ;;
        *) fail_task "$(basename "$file") is built for $built, but this database runs on $ARCH." ;;
    esac
done
@else
targets=({!! $sh->arg($fetch->package ?? '') !!})
@endif
apt-get install --download-only -y -qq --no-install-recommends{{ $fetch->replacing === [] ? '' : ' --allow-downgrades' }} -o Dir::Cache::archives="$DIR" "${targets[@]}" > /dev/null 2> "$ERRORS" || fail_task "Could not fetch the package: $(tail -n 3 "$ERRORS")"
packages='[]'
files='[]'
extensions='[]'
untrusted=()
queue=()
refusal=''
replaced=false
newer=false
unchanged=''
sql_scripts=''
declare -A shipped_by
declare -A fetched_names
offer_extension() {
    local owner=$1 extension=$2 scripts=$3 control=$4 version
    if ! [[ $extension =~ ^[a-z0-9_]+$ ]]; then
        refusal="$owner has an extension named $extension, which the app can't offer."
        return
    fi
    if grep -qiE 'CREATE[[:space:]]+(OR[[:space:]]+REPLACE[[:space:]]+)?(PROCEDURAL[[:space:]]+)?LANGUAGE' <<< "$scripts"; then
        untrusted+=("$extension")
        return
    fi
    version=$(sed -nE "s/^default_version *= *'([^']*)'.*/\1/p" <<< "$control")
    extensions=$(jq -c --arg name "$extension" --arg version "$version" '. + [{name: $name, defaultVersion: $version}]' <<< "$extensions")
    shipped_by[$extension]=$owner
}
for file in "$DIR"/*.deb; do
    [ -e "$file" ] || continue
    name=$(dpkg-deb -f "$file" Package)
    contents=$(dpkg-deb -c "$file")
    if grep -E "postgresql/[0-9]+/" <<< "$contents" | grep -vE "postgresql/$LINE/" > /dev/null; then
        refusal="$name is not a package for PostgreSQL $LINE."
    fi
    if is_installed "$name" && ! is_replacing "$name"; then
        refusal="Adding it would replace $name, which is already installed."
    fi
@if ($fetch->replacing !== [])
    if is_replacing "$name"; then
        replaced=true
        fetched_names[$name]=1
        new_version=$(dpkg-deb -f "$file" Version)
        old_version=$(dpkg-query -W -f='${Version}' "$name" 2> /dev/null || true)
        if [ -z "$old_version" ] || dpkg --compare-versions "$new_version" gt "$old_version"; then
            newer=true
        elif dpkg --compare-versions "$new_version" lt "$old_version"; then
            refusal="$name $new_version is older than the installed $old_version."
        else
            unchanged="$name $new_version"
        fi
    fi
    sql_scripts+="$(grep -oE "usr/share/postgresql/$LINE/extension/[^/ ]+\.sql" <<< "$contents" | sed 's#.*/##' || true)"$'\n'
@endif
    mapfile -t -O "${#queue[@]}" queue < <(names_in "$(dpkg-deb -f "$file" Depends), $(dpkg-deb -f "$file" Pre-Depends)")
    packages=$(jq -c --arg item "$name=$(dpkg-deb -f "$file" Version)" '. + [$item]' <<< "$packages")
    files=$(jq -c --arg sha "$(sha256sum "$file" | cut -d' ' -f1)" --arg name "$(basename "$file")" --arg arch "$(dpkg-deb -f "$file" Architecture)" --arg package "$name" '. + [{sha256: $sha, fileName: $name, architecture: $arch, package: $package}]' <<< "$files")
    while IFS= read -r control; do
        [ -z "$control" ] && continue
        extension=$(basename "$control" .control)
        offer_extension "$name" "$extension" "$(dpkg-deb --fsys-tarfile "$file" | tar -xO --wildcards "./usr/share/postgresql/$LINE/extension/$extension--*.sql" 2> /dev/null || true)" "$(dpkg-deb --fsys-tarfile "$file" | tar -xO "./$control")"
    done <<< "$(grep -oE "usr/share/postgresql/$LINE/extension/[^/ ]+\.control" <<< "$contents" || true)"
done
declare -A seen
needed=()
while [ "${#queue[@]}" -gt 0 ]; do
    dependency=${queue[0]}
    queue=("${queue[@]:1}")
    [ -n "${seen[$dependency]+set}" ] && continue
    seen[$dependency]=1
    is_installed "$dependency" && ! is_replacing "$dependency" || continue
    needed+=("$dependency")
    mapfile -t -O "${#queue[@]}" queue < <(names_in "${installed[$dependency]}")
done
requires=$(printf '%s\n' ${needed[@]+"${needed[@]}"} | jq -R 'select(. != "")' | jq -cs .)
@if ($fetch->replacing !== [])
for replaced_name in "${replacing[@]}"; do
    [ -n "${fetched_names[$replaced_name]+set}" ] && continue
    is_installed "$replaced_name" || continue
    kept_files=$(dpkg -L "$replaced_name" 2> /dev/null || true)
    sql_scripts+="$(grep -oE "usr/share/postgresql/$LINE/extension/[^/ ]+\.sql$" <<< "$kept_files" | sed 's#.*/##' || true)"$'\n'
    while IFS= read -r control; do
        [ -z "$control" ] && continue
        extension=$(basename "$control" .control)
        offer_extension "$replaced_name" "$extension" "$(cat "/usr/share/postgresql/$LINE/extension/$extension"--*.sql 2> /dev/null || true)" "$(cat "/$control")"
    done <<< "$(grep -oE "usr/share/postgresql/$LINE/extension/[^/ ]+\.control$" <<< "$kept_files" || true)"
done
if ! "$replaced"; then
@if ($fetch->source === \App\Enums\Databases\PackageSource::Upload)
    refusal="$(cd "$DIR" && printf '%s\n' *.deb | paste -sd, - | sed 's/,/, /g') "{!! $sh->arg("are not a new version of {$fetch->replacing[0]}.") !!}
@else
    already_installed "$(dpkg-query -W -f='${Package} ${Version}' {!! $sh->arg($fetch->package ?? $fetch->replacing[0]) !!}) is already installed."
@endif
elif ! "$newer" && [ -z "$refusal" ]; then
    already_installed "$unchanged is already installed."
fi
updates_from() {
    local extension=$1 from=$2 script rest
    while IFS= read -r script; do
        [ -z "$script" ] && continue
        rest=${script#"$extension--"}
        rest=${rest%.sql}
        if [ "$rest" != "${rest#*--}" ] && [ "${rest%%--*}" = "$from" ]; then
            printf '%s\n' "${rest#*--}"
        fi
    done < <(grep -E "^$extension--" <<< "$sql_scripts" || true)
}
enabled=({!! implode(' ', array_map(fn (\App\DTOs\Databases\EnabledExtension $enabled): string => $sh->arg("{$enabled->name}={$enabled->version}@{$enabled->schema}"), $fetch->enabled)) !!})
for entry in ${enabled[@]+"${enabled[@]}"}; do
    extension=${entry%%=*}
    from=${entry#*=}
    from=${from%@*}
    schema=${entry##*@}
    target=$(jq -r --arg name "$extension" '.[] | select(.name == $name) | .defaultVersion' <<< "$extensions")
    if [ -z "$target" ]; then
        [ -n "$refusal" ] || refusal="$extension is enabled in $schema, but the new version no longer ships it."
        continue
    fi
    unset reached
    declare -A reached=(["$from"]=1)
    walk=("$from")
    while [ "${#walk[@]}" -gt 0 ]; do
        version=${walk[0]}
        walk=("${walk[@]:1}")
        while IFS= read -r next; do
            [ -z "$next" ] && continue
            [ -n "${reached[$next]+set}" ] && continue
            reached[$next]=1
            walk+=("$next")
        done < <(updates_from "$extension" "$version")
    done
    if [ -z "${reached[$target]+set}" ]; then
        [ -n "$refusal" ] || refusal="$extension $from is enabled in $schema, but ${shipped_by[$extension]} has no update path from $from to $target."
    fi
done
@endif
if [ -z "$refusal" ] && [ "$extensions" = '[]' ] && [ "${#untrusted[@]}" -gt 0 ]; then
    refusal="$(jq -r '.[0] // "The package"' <<< "$packages" | cut -d= -f1) only ships untrusted languages (${untrusted[*]}), which the app doesn't offer."
fi
if [ -z "$refusal" ] && [ "$extensions" = '[]' ]; then
    refusal="$(jq -r '.[0] // "The package"' <<< "$packages" | cut -d= -f1) ships no PostgreSQL extension."
fi
result "$(jq -cn --argjson packages "$packages" --argjson files "$files" --argjson extensions "$extensions" --argjson requires "$requires" --argjson already false --arg refusal "$refusal" '{packages: $packages, files: $files, extensions: $extensions, requires: $requires, alreadyInstalled: $already, refusal: (if $refusal == "" then null else $refusal end)}')"
