# 0014. PostgreSQL extensions: a curated set in the image, enabled per schema

- Status: Accepted
- Date: 2026-11-23

## Context

Users want extensions such as pgvector, PostGIS and pg_cron, and every database needs a different set. `CREATE EXTENSION` only turns on files that are already on the server, and most popular extensions are not marked trusted, so a normal role cannot create them. Nodes of one database must run the same software ([0003](0003-engine-images-and-frozen-runtime.md)). Hosted services (DigitalOcean, Aiven) ship a fixed list in the server and enable it through an allowlist.

## Decision

- **Curated set in the image.** Every PostgreSQL image ships the safe PGDG extension packages for its line, pinned in `engine-postgres/versions.json`. Nothing is enabled or preloaded by default. Packages that fight Patroni, pgBouncer or replication, reach the server or other users' data, or need an outside service are left out.
- **One catalog in the app.** `PostgresExtensionCatalog` names what users may enable, each with a group and, where needed, a preload library. What a database offers is that catalog intersected with `pg_available_extensions` on its primary, so older images simply offer less. The image ships ogr_fdw for its GDAL stack, but the catalog leaves it out: its foreign servers read any file on the host, and write roles get USAGE on extension wrappers.
- **Two ways to enable.** The Extensions tab, the REST API and MCP enable, update and disable an extension per schema as one action and one job ([0005](0005-operations-as-one-job.md)), running as `postgres` on the primary. Users can also run `CREATE EXTENSION` themselves: pgextwlist loads through `local_preload_libraries`, and each login role gets `extwlist.extensions` per schema only where it has CREATE on that database, so read-only roles get nothing. Extensions that need a preload library are enabled through the app only.
- **PostgreSQL is the source of truth.** The app keeps no extension table; it reads the enabled extensions from `pg_extension` live, so extensions created with SQL, by migrations, restores and failovers always show. What an image offers only depends on the image, so that list is cached per image digest. The enabled list per schema is cached for a minute and cleared after every enable, update and disable from the app, so changes made with SQL show within that minute.
- **Preload libraries are database settings.** `DatabaseSettings::$extensions` lists the libraries a database loads after `pg_stat_statements`. Every enable of such an extension saves the library and rolls the settings out, read-only nodes included, restarting node by node only when something is pending, so a failed roll can simply be retried. The last disable of every extension that loads it removes it without a forced restart. A disable never cascades and is refused while the extension's own configuration tables (such as pg_cron's jobs) hold user rows. Catalog entries marked single-schema (pg_cron) live in one schema per database and follow the schema they were last enabled in (`ExtensionLibraries::$schemas`); pg_cron runs on background workers. TimescaleDB telemetry is off.
- **Usage follows write access.** Enabling an extension, and every write grant, gives write roles USAGE on the extension's foreign data wrappers and on pg_cron's `cron` schema, because extensions enabled by the app are owned by `postgres`.
- **Disable never cascades.** `DROP EXTENSION` runs without `CASCADE`, and PostgreSQL's refusal reaches the user as-is.

## Consequences

- Images grow by about 460 MB installed, mostly GDAL and PROJ for the geo extensions.
- An extension outside the curated set needs an image change, so users ask for it through an issue or pull request.
- Each node configure checks whether its image has `pgextwlist.so` and only then renders `local_preload_libraries`, because one release number is published both with and without the curated packages and a missing library would break every connection. The per-role allowlist is written either way; it does nothing until the module loads.
- An image roll-out re-applies the allowlist, so roles that existed before can install on the new image.

## Alternatives considered

- **Installing packages at runtime with `apt-get`:** rejected for this decision, nodes could drift and pinned versions disappear from the repository.
- **A custom image per database:** rejected, it adds Docker builds and image copies to every roll-out.
- **Building extensions from uploaded source code:** dropped; it needs compilers and a rebuild on every image upgrade, while a `.deb` built in the user's own CI does the same job.
- **Image variants such as `postgres-18-gis`:** rejected, more images to build and the choice is fixed at creation.
- **An app table of enabled extensions:** rejected, it would drift from SQL users' changes and from restores.

## Evidence

- `app/Engines/Drivers/Postgres/PostgresExtensionCatalog.php`
- `app/Engines/Drivers/Postgres/PostgresExtensions.php`
- `app/Engines/Contracts/Extensions.php`
- `app/Engines/Drivers/Postgres/PostgresFiles.php`
- `app/DTOs/Databases/ExtensionLibraries.php`
- `app/Actions/Databases/Extensions/EnableSchemaExtension.php`
- `resources/views/engines/postgres/extensions/`
- `resources/views/engines/postgres/accounts/_grants.blade.php`
