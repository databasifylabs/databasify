# Phase 2: the engine images (engine repository)

Paths here are from the root of your engine clone. The SDK's skill, `.claude/skills/adding-engine-image` in [`databasifylabs/engine-sdk`](https://github.com/databasifylabs/engine-sdk), covers the same ground in more detail; follow it when you work there. `$IMAGES` below is `tools/images.sh` in a clone of `engine-sdk` at the version the engine's `go.mod` requires, run from the engine repository root.

## Files

| Path                                   | Purpose                                                                                                                                                                                                                                |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `images/<engine>/Dockerfile`           | Extends the official image. Copy an existing one (`engine-mongodb`, `engine-valkey`) and keep the stage layout. One Dockerfile serves every line of the engine.                                                                        |
| `images/<engine>/smoke/processes.json` | A minimal process manifest the smoke test runs through the engine binary.                                                                                                                                                                    |
| `images/<engine>/conformance`          | An empty file. Its presence makes `$IMAGES smoke` run the SDK's conformance suite (`conformance`) against the smoke container: every offered command, `unsupported` for the rest, exit codes, and passwords never echoed. Add it once the engine passes. |
| `images/<engine>/smoke/check.sh`       | Waits for the engine, asserts `$DATABASIFY_RELEASE`, exercises the bundled tools, checks UID 64999. It runs against every line, so it must tolerate fields older releases lack (`serverStatus().tcmalloc.usingPerCPUCaches ?? false`). |
| `images/<engine>/.trivyignore`         | Only for CVEs bundled upstream that we cannot fix; group them with a comment saying when to remove them (see `engine-mongodb`'s `images/mongodb/.trivyignore`). Try `upgrades` first.                                                                     |
| `images/<engine>/repositories.sh`      | Only when a package comes from a third-party repository the base image lacks (MySQL's Percona repo). Both the Dockerfile (bind mount) and the SDK's `tools/packages.sh` use it, it is part of the content hash, and `.dockerignore` lets it through.                             |
| `versions.json`                        | One entry per line under the top-level `images` object (`images["<engine>-<line>"]`); `registry` (`ghcr.io/<owner>`), `sdk` and `go` are shared by every image.                                                                                        |
| `README.md`                            | The engine's lines and every bundled tool's license.                                                                                                                                                                                  |

No workflow change is needed for a new line: the CI matrix, tags and refresh all come from `versions.json`. A new engine repository copies `.github/workflows/images.yml` and `refresh.yml` from the template. The engine's Go package (phase 1) is built into the image by the `wrapper` stage.

## Dockerfile rules

- Stages: `wrapper` (always; `WORKDIR /src/<repository>`, `COPY --from=modules . /src/`, then `go.mod go.sum*`, `cmd` and the engine's packages), then one build stage per tool compiled from source, then `FROM ${BASE_IMAGE}`.
- Final stage installs `$PACKAGES` (pinned `name=version`), removes `/usr/local/bin/gosu` when the base ships it (Valkey and Redis bases do not), copies the tools and the engine binary.
- Remap the engine user: `groupmod -g 64999 <user>; usermod -u 64999 -g 64999 <user>; find / -xdev \( -uid 999 -o -gid 999 \) -exec chown -h 64999:64999 {} +`.
- Labels `io.databasify.{engine,release,line,sdk}`, env `DATABASIFY_ENGINE`, `DATABASIFY_RELEASE`, `DATABASIFY_LINE`, `STOPSIGNAL SIGTERM`, `ENTRYPOINT ["/usr/local/bin/databasify", "serve"]`.
- Runtime environment the engine needs on every host belongs in the image `ENV`, not in the app's `ProcessSpec` env, which would override it. Example: `GLIBC_TUNABLES=glibc.pthread.rseq=1` for MongoDB, because the official image's `rseq=0` makes mongod 8.x crash or refuse to start on Linux 6.19–7.0.13 (SERVER-121912). CI runners move to new kernels before servers do.
- `$IMAGES smoke` checks that `databasify info` prints `engine=<engine>`, then the tool contract every image must pass: `bash ps ss ip jq openssl curl`. Older bases (Ubuntu 22.04 for MongoDB 7.0) lack some; inspect each base and add only what is missing:

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
- Put `"0"` as every package version, then run `$IMAGES refresh <key>` — it moves the base, copied images, packages and Go modules to the newest patch within the line. It may also move the shared `go` digest: revert that unless you mean to rebuild every image.
- Base digest: `docker buildx imagetools inspect <image>:<tag> --format '{{json .Manifest}}' | jq -r .digest`.
- Go code: `modules` uses `go get` and needs a semver-correct module path; anything else (no `/vN` suffix, vendored trees) goes in `sources` with a verified tarball sha256 (`redis-shake`, `pbm`, `mongoshake`). `upgrades` bumps vendored modules past CVEs.
- Build args (`$IMAGES args <key>`), with `<NAME>` the upper-cased key and non-alphanumerics as `_`: `copies` → `<NAME>_IMAGE`; `modules` → `<NAME>_VERSION`; `sources` → `<NAME>_VERSION`, `<NAME>_SHA256`, `<NAME>_UPGRADES`.
- Check the bundled tools support the line before adding it. When a tool lags (PBM 2.17 has no MongoDB 9.0), still ship the line and turn the feature off for it in the app (see capabilities).

## Verify locally (Docker must be running)

These local checks are the contributor's proof: CI on a fork cannot publish images, so the pull request shows the build, smoke, conformance and scan results you ran.

```bash
go vet ./... && go test -race -count=1 ./...
$IMAGES check
$IMAGES build <key> --load --tag smoke:<key>
$IMAGES smoke smoke:<key> <engine>
```

Then scan exactly like CI (fails on fixable HIGH/CRITICAL):

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$PWD:/repo:ro" "$(sed -n 's/^  TRIVY_IMAGE: //p' <sdk clone>/.github/workflows/engine-images.yml)" \
  image --scanners vuln --severity CRITICAL,HIGH --ignore-unfixed --exit-code 1 --ignorefile /repo/images/<engine>/.trivyignore smoke:<key>
```

Lint: `docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:v0.10.0 -s bash -S warning images/<engine>/smoke/check.sh`.

## After the pull request merges (maintainers)

A branch pushed to the engine repository itself builds test tags (`<release>-<sha7>`); the app never picks those, because it takes only exact `<line>.x` releases. A merge to `main` publishes the release tags. Watch the `images` workflow (`gh run watch <id> --exit-status`); a failing line does not publish. A new `databasify-<engine>` GHCR package is created **private**: a maintainer makes it public and gives the engine repository the Write role under Manage Actions access. Verify anonymous pulls and note the digest:

```bash
tok=$(curl -s "https://ghcr.io/token?scope=repository:databasifylabs/databasify-<engine>:pull" | jq -r .token)
curl -sI -H "Authorization: Bearer $tok" -H "Accept: application/vnd.oci.image.index.v1+json" https://ghcr.io/v2/databasifylabs/databasify-<engine>/manifests/<release> | grep -i docker-content-digest
```

A rebuild of the same release (new digest) shows up in the app as "Refresh image", which is useful to test the image-swap rollout.

Existing databases get the new code only through the image: the weekly maintenance window moves each one to the newest image of its line, one node at a time.
