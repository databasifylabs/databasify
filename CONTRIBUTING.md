# Contributing to Databasify

Thanks for your interest in Databasify. Bug reports, fixes and documentation improvements are welcome.

## Before you start

- **Small and obvious changes** (typos, small bugs, docs) can go straight to a pull request.
- **Features, new engines and larger changes** need an issue first, so we can agree on the approach before you write code.
- Read the [architecture decisions](docs/adr/README.md) that touch the files you change. A change that needs a different decision adds a new ADR in the same pull request.

## Setup

Requirements: PHP 8.5, Composer, Node.js and SQLite.

```bash
git clone https://github.com/databasifylabs/databasify.git
cd databasify
composer run setup
composer run dev
```

`composer run setup` installs the dependencies, writes `.env`, generates the app key, migrates the database and builds the frontend.

### The Updates page in development

The Updates page only exists on Docker installs, where the host updater is installed. `composer run setup` also prepares a local stand-in for it: an `installed` marker in `storage/updater` (git-ignored) and `DATABASIFY_UPDATER_PATH` in `.env` pointing at that folder, so **Updates** shows in the sidebar.

- Nothing on your machine runs the update. Clicking **Update** only creates `storage/updater/request/update`; delete it to reset the page.
- To see the other states, write `storage/updater/status.json` by hand in the format `scripts/update.sh` writes. The page polls it every 2 seconds while its `state` is `running`.
- To hide the page, remove the `DATABASIFY_UPDATER_PATH` line from `.env` and run `php artisan config:clear`.

Branch from `0.x` and open your pull request against `0.x`.

## Checks

Run these before you open a pull request. CI runs them too.

```bash
php artisan test
vendor/bin/phpstan analyse
vendor/bin/pint
npm run check
```

- Add or update feature tests (`tests/Feature`) for behaviour changes.
- PHPStan runs at max level: narrow types properly instead of ignoring errors.
- Keep controllers thin and put business logic in single-purpose Action classes, following the existing code.

## Commits and pull requests

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>[optional scope]: <description>
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`.

- One pull request is one logical change. Do not mix in unrelated refactors or formatting.
- Describe what changed and how you tested it. For engine or server scripts, say which engine, version and OS you ran it on.
- Add screenshots for UI changes.

## Security issues

Do not open a public issue for a vulnerability. Follow [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the [Apache License 2.0](LICENSE).
