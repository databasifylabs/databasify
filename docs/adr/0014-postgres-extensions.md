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
- **Custom packages per database.** Each database can add `.deb` packages the image doesn't ship: from the engine's official repository (source `official`, PGDG for PostgreSQL), another package repository (source `repository`, with its signing key) or uploaded files. The package format is an engine fact (`EngineFacts::$packageFormat`, `deb` for PostgreSQL): it sets the accepted upload extension, the repository line pattern and the labels, so an RPM-based engine can offer packages through its own driver scripts. They're fetched once on the primary, saved in the app by SHA-256, and installed offline on every node and every deploy; host files only get their final name once complete, and a failed install is purged from every node it reached. Repository keys download over https only, repository options such as `[signed-by=…]` are replaced by the app's own, and API and MCP responses never show credentials in a repository line. Their extensions join the catalog as group Custom and the database's SQL allowlist. A package is refused when it's for another line, ships no extension, would replace a package that's already installed and isn't one of the database's own packages, or clashes with a curated or other package's extension. Extensions that create an untrusted language are left out, so SQL users can't run OS commands. A package that depends on another custom package keeps its own copy of those files, and a removal purges only what no other package still lists. A package can't be removed while one of its extensions is enabled; the job takes its extensions off the allowlist and checks again before it purges. A package can be brought to a newer version while its extensions stay enabled: by adding it again with Add package (a newer build of a package the database already has, from the same source, replaces it), or for PGDG and apt-repository packages by checking for and installing the newest version on request. The new build is fetched on the primary and must be newer, keep every enabled extension, and ship an update path from every enabled version. It installs on replicas first and the primary last, and on a failure every touched node goes back to the old files. Each schema then moves to the new SQL version with Update. A package that another package depends on can't be updated until that package is removed. Before an upgrade, every package is tried on the new image in a throwaway container; the upgrade waits for that and uses its result only while the database still has the same packages at the same versions. A failed try raises one alert and isn't repeated for the same image and packages. A package the new image installs itself is dropped from the list. Hosts keep package files by SHA-256 name, and a file whose hash no longer matches is uploaded again. Package files nothing uses any more are removed from the nodes and the app after a removal and after each roll-out. The app doesn't build extensions from source; users with their own code build a `.deb` for Debian trixie and upload it or publish it to their own apt repository. PostgreSQL takes `.deb` packages, because its engine runs Debian. A database with custom packages keeps all its nodes on one CPU type: a server whose CPU (read with the server facts) differs from the packages' builds is refused as a new node, and a package can't be added while the nodes run on different CPU types. Uploaded files must match the nodes' CPU and are deleted from the app once the fetch ends or the request is refused.
- **Migrations.** The check reports source extensions this database cannot offer as problems, refuses TimescaleDB sources for now, and ignores tables that belong to extensions. Extensions of custom packages count as offered. `pg_restore` runs with `extwlist.extensions` set to the offered list, so the dump's own `CREATE EXTENSION` and `COMMENT ON EXTENSION` lines run as the owner and keep each extension's schema.

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
- `resources/views/engines/postgres/extensions/packages/`
- `app/Actions/Databases/Extensions/ApplyExtensionPackage.php`
- `app/Actions/Databases/Extensions/ApplyExtensionPackageUpdate.php`
