# Phase 1 — the engine image (`../engines`)

## Files

| Path                                   | Purpose                                                                                                                                                                                                                                |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `images/<engine>/Dockerfile`           | Extends the official image. Copy an existing one (`images/mongodb`, `images/valkey`) and keep the stage layout. One Dockerfile serves every line of the engine.                                                                        |
| `images/<engine>/smoke/processes.json` | A minimal process manifest the smoke test runs through the wrapper.                                                                                                                                                                    |
| `images/<engine>/smoke/check.sh`       | Waits for the engine, asserts `$DATABASIFY_RELEASE`, exercises the bundled tools, checks UID 64999. It runs against every line, so it must tolerate fields older releases lack (`serverStatus().tcmalloc.usingPerCPUCaches ?? false`). |
| `images/<engine>/.trivyignore`         | Only for CVEs bundled upstream that we cannot fix; group them with a comment saying when to remove them (see `images/mongodb/.trivyignore`). Try `upgrades` first.                                                                     |
| `images/<engine>/repositories.sh`      | Only when a package comes from a third-party repository the base image lacks (MySQL's Percona repo). Both the Dockerfile (bind mount) and `images/packages.sh` use it, and it is part of the content hash.                             |
| `versions.json`                        | One entry per line under the top-level `images` object (`images["<engine>-<line>"]`); `registry`, `wrapper` and `go` are shared by every image.                                                                                        |
| `README.md`                            | Add the engine to the intro and its license (and every bundled tool's license) to the Licenses section.                                                                                                                                |

No workflow or Go change is needed: the CI matrix, tags and refresh all come from `versions.json`.

## Dockerfile rules

- Stages: `wrapper` (always), then one build stage per tool compiled from source, then `FROM ${BASE_IMAGE}`.
- Final stage installs `$PACKAGES` (pinned `name=version`), removes `/usr/local/bin/gosu` when the base ships it (Valkey and Redis bases do not), copies tools and the wrapper.
- Remap the engine user: `groupmod -g 64999 <user>; usermod -u 64999 -g 64999 <user>; find / -xdev \( -uid 999 -o -gid 999 \) -exec chown -h 64999:64999 {} +`.
- Labels `io.databasify.{engine,release,wrapper}`, env `DATABASIFY_ENGINE`, `DATABASIFY_RELEASE`, `STOPSIGNAL SIGTERM`, `ENTRYPOINT ["/usr/local/bin/databasify", "serve"]`.
- Runtime environment the engine needs on every host belongs in the image `ENV`, not in the app's `ProcessSpec` env, which would override it. Example: `GLIBC_TUNABLES=glibc.pthread.rseq=1` for MongoDB, because the official image's `rseq=0` makes mongod 8.x crash or refuse to start on Linux 6.19–7.0.13 (SERVER-121912). CI runners move to new kernels before servers do.
- `images.sh smoke` checks that `databasify info` prints `engine=<engine>`, then the tool contract every image must pass: `bash ps ss ip jq openssl curl`. Older bases (Ubuntu 22.04 for MongoDB 7.0) lack some; inspect each base and add only what is missing:

    ```bash
    docker run --rm --entrypoint bash <base>@<digest> -c 'for t in bash ps ss ip jq openssl curl; do command -v $t >/dev/null || echo "missing $t"; done'
    ```

## versions.json entry

```json
{
    "images": {
        "<engine>-<line>": {
            "engine": "<engine>",
            "line": "<line>",
            "release": "<exact version>",
            "base": {
                "image": "<official>",
                "tag": "<release><suffix>",
                "digest": "sha256:…"
            },
            "packages": { "iproute2": "0", "curl": "0" },
            "copies": { "<name>": { "image": "…", "tag": "…", "digest": "…" } },
            "modules": {
                "<name>": { "module": "github.com/…", "version": "…" }
            },
            "sources": {
                "<name>": { "version": "…", "sha256": "…", "upgrades": [] }
            }
        }
    }
}
```

- `release` must match the official tag; the tag suffix (e.g. `-trixie`) is kept by `images.sh refresh`.
- Put `"0"` as every package version, then run `images/images.sh refresh <key>` — it moves the base, copied images, packages and Go modules to the newest patch within the line. It may also move the shared `go` digest: revert that unless you mean to rebuild every image.
- Base digest: `docker buildx imagetools inspect <image>:<tag> --format '{{json .Manifest}}' | jq -r .digest`.
- Go code: `modules` uses `go get` and needs a semver-correct module path; anything else (no `/vN` suffix, vendored trees) goes in `sources` with a verified tarball sha256 (`redis-shake`, `pbm`, `mongoshake`). `upgrades` bumps vendored modules past CVEs.
- Build args (`images.sh args <key>`), with `<NAME>` the upper-cased key and non-alphanumerics as `_`: `copies` → `<NAME>_IMAGE`; `modules` → `<NAME>_VERSION`; `sources` → `<NAME>_VERSION`, `<NAME>_SHA256`, `<NAME>_UPGRADES`.
- Check the bundled tools support the line before adding it. When a tool lags (PBM 2.17 has no MongoDB 9.0), still ship the line and turn the feature off for it in the app (see capabilities).

## Verify locally (Docker must be running)

```bash
images/images.sh build <key> --load --tag smoke:<key>
images/images.sh smoke smoke:<key> <engine>
```

Then scan exactly like CI (fails on fixable HIGH/CRITICAL):

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$PWD:/repo:ro" "$(sed -n 's/^  TRIVY_IMAGE: //p' .github/workflows/images.yml)" \
  image --scanners vuln --severity CRITICAL,HIGH --ignore-unfixed --exit-code 1 --ignorefile /repo/images/<engine>/.trivyignore smoke:<key>
```

Lint: `docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:v0.10.0 -s bash -S warning images/<engine>/smoke/check.sh`.

## After the user approves the push

Watch the `images` workflow (`gh run watch <id> --exit-status`); a failing line does not publish. A new `databasify-<engine>` GHCR package is created **private**: the user must make it public and give `databasifylabs/engines` the Write role under Manage Actions access. Verify anonymous pulls and note the digest:

```bash
tok=$(curl -s "https://ghcr.io/token?scope=repository:databasifylabs/databasify-<engine>:pull" | jq -r .token)
curl -sI -H "Authorization: Bearer $tok" -H "Accept: application/vnd.oci.image.index.v1+json" https://ghcr.io/v2/databasifylabs/databasify-<engine>/manifests/<release> | grep -i docker-content-digest
```

A rebuild of the same release (new digest) shows up in the app as "Refresh image" — useful to test the image-swap rollout.
