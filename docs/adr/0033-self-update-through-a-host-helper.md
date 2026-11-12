# 0033. Self-update through a host helper

- Status: Accepted
- Date: 2026-11-12

## Context

Owners updated Databasify only over SSH with `curl -fsSL https://get.databasify.io/update.sh | sudo bash`. They should see a new release, read its notes and update from the app. The update has to run on the host: it edits `/opt/databasify/.env`, pulls images and restarts the container the app runs in. Giving the app container the Docker socket or root SSH to its own host would turn any app compromise into root on the host.

## Decision

- `version.json` (`version`, `published_at`, `url`, `notes` of the latest stable release) is the single release source. It is not in git (`/scripts/version.json` is ignored): the `bump` job in `.github/workflows/release.yml` builds it from each stable GitHub Release and attaches it to that release, and `scripts/_redirects` sends `get.databasify.io/version.json` to `github.com/databasifylabs/databasify/releases/latest/download/version.json`, which GitHub points at the newest stable release; `published_version` in `scripts/lib.sh` reads its `"version"` line with `sed`.
- The app checks it once a day (`updates:check`, daily, on one server) and on **Check now**, and stores the release in the `latest_release` setting. A failed check keeps the previous release and check time, shows an error on the page and fails the command. Release notes are rendered with `league/commonmark`, raw HTML stripped and unsafe links dropped.
- The app only asks for an update. `/opt/databasify/updater/` is bind-mounted at `/app/updater`. The app can write only `request/` (owned by uid 33, the container's `www-data`, mode `0700`); the systemd unit `databasify-update.path` watches it and starts the oneshot `databasify-update.service`, which runs the latest `update.sh` from `get.databasify.io` as root (with `pipefail`, so a failed download fails the unit). `update.sh` clears `request/` right after it writes the running status, so the app always sees either the request or the status; `ExecStopPost` clears anything left so the path unit never loops. The request's content is never read.
- `update.sh` writes `updater/status.json` (state, from, to, times, rollback and the fixed step list with each step's state) after every step and keeps only the latest log in `updater/update.log`, whether it runs from the app or over SSH. `install.sh` and `update.sh` install or refresh the units and the `installed` marker; `uninstall.sh --purge` removes the units.
- Before pulling, `update.sh` records the running app image ID. When restarting fails or the new version is not healthy, it tags that image back, restarts and reports `rolled_back` in the status; a rollback that cannot restore the image says so. An `EXIT` trap marks a run that stops unexpectedly as failed, so the status is never left running. Without a running container it updates without rollback and says so.
- Any signed-in user can update. The app refuses when `DATABASIFY_VERSION` pins a version, when the helper is not installed, while a request or an update is pending, or when no newer release is stored.

## Consequences

- One update path: the page and SSH run the same `update.sh`.
- Migrations run when the container starts, so a rolled-back release can meet a schema the newer release already migrated. Every release's migrations must stay readable by the release before it: add columns and tables first, drop them a release later.
- Release notes edited on GitHub after the tag push reach installs only when the `bump` job runs again for that release.
- A tag pushed without a GitHub Release fails the `bump` job, so `config/app.php` is not bumped either.
- A tag pushed without a GitHub Release fails the `bump` job, so `config/app.php` is not bumped either.
- The units hardcode `/opt/databasify`; a different install folder would need edited units.
- Installs from before this decision get the helper the first time `update.sh` runs over SSH; until then the page shows that command.

## Alternatives considered

- **Docker socket in the app container:** rejected; container compromise would be root on the host.
- **The app SSHes into its own host as root (Coolify's approach):** rejected; it needs root SSH login on the host and the same blast radius.
- **Keeping every release in `version.json`:** rejected by the owner; only the latest release's notes are shown, with a link to all releases.

## Evidence

- `scripts/_redirects`
- `.gitignore`
- `scripts/lib.sh`
- `scripts/update.sh`
- `scripts/install.sh`
- `scripts/uninstall.sh`
- `scripts/compose.yaml`
- `scripts/databasify-update.path`
- `scripts/databasify-update.service`
- `.github/workflows/release.yml`
- `app/Actions/Updates/CheckForRelease.php`
- `app/Actions/Updates/StartUpdate.php`
- `app/Services/Updates/HostUpdater.php`
- `app/Queries/Updates/UpdatesPageQuery.php`
- `app/Http/Controllers/Updates/UpdateController.php`
- `resources/js/pages/updates/index.tsx`
