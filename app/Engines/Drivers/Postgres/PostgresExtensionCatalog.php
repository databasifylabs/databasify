<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Data\CatalogExtension;
use App\Enums\Engines\ExtensionGroup;
use App\Models\Database;

final readonly class PostgresExtensionCatalog
{
    /**
     * @return list<CatalogExtension>
     */
    public static function entries(): array
    {
        return [
            new CatalogExtension('autoinc', 'autoinc', 'Trigger that fills a column from a sequence.', ExtensionGroup::BuiltIn),
            new CatalogExtension('bloom', 'bloom', 'Bloom filter index access method.', ExtensionGroup::BuiltIn),
            new CatalogExtension('btree_gin', 'btree_gin', 'GIN operator classes for common data types.', ExtensionGroup::BuiltIn),
            new CatalogExtension('btree_gist', 'btree_gist', 'GiST operator classes for common data types.', ExtensionGroup::BuiltIn),
            new CatalogExtension('citext', 'citext', 'Case-insensitive text type.', ExtensionGroup::BuiltIn),
            new CatalogExtension('cube', 'cube', 'Multi-dimensional cube type.', ExtensionGroup::BuiltIn),
            new CatalogExtension('dblink', 'dblink', 'Queries other PostgreSQL databases.', ExtensionGroup::BuiltIn),
            new CatalogExtension('dict_int', 'dict_int', 'Text search dictionary for integers.', ExtensionGroup::BuiltIn),
            new CatalogExtension('dict_xsyn', 'dict_xsyn', 'Text search dictionary for extended synonyms.', ExtensionGroup::BuiltIn),
            new CatalogExtension('earthdistance', 'earthdistance', 'Great-circle distances on the Earth.', ExtensionGroup::BuiltIn),
            new CatalogExtension('fuzzystrmatch', 'fuzzystrmatch', 'Similarity and distance between strings.', ExtensionGroup::BuiltIn),
            new CatalogExtension('hstore', 'hstore', 'Key-value pairs in one column.', ExtensionGroup::BuiltIn),
            new CatalogExtension('insert_username', 'insert_username', 'Trigger that records who changed a row.', ExtensionGroup::BuiltIn),
            new CatalogExtension('intagg', 'intagg', 'Integer aggregator and enumerator.', ExtensionGroup::BuiltIn),
            new CatalogExtension('intarray', 'intarray', 'Functions and indexes for integer arrays.', ExtensionGroup::BuiltIn),
            new CatalogExtension('isn', 'isn', 'Types for ISBN, ISSN, EAN and UPC numbers.', ExtensionGroup::BuiltIn),
            new CatalogExtension('lo', 'lo', 'Large object maintenance.', ExtensionGroup::BuiltIn),
            new CatalogExtension('ltree', 'ltree', 'Hierarchical tree-like labels.', ExtensionGroup::BuiltIn),
            new CatalogExtension('moddatetime', 'moddatetime', 'Trigger that tracks the last change time.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pg_buffercache', 'pg_buffercache', 'Inspects the shared buffer cache.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pg_prewarm', 'pg_prewarm', 'Loads relation data into the buffer cache.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pg_stat_statements', 'pg_stat_statements', 'Statistics of the SQL statements run.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pg_trgm', 'pg_trgm', 'Trigram similarity and indexes for text search.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pgcrypto', 'pgcrypto', 'Hashing and encryption functions.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pgrowlocks', 'pgrowlocks', 'Shows row-level locks.', ExtensionGroup::BuiltIn),
            new CatalogExtension('pgstattuple', 'pgstattuple', 'Tuple-level statistics and bloat.', ExtensionGroup::BuiltIn),
            new CatalogExtension('postgres_fdw', 'postgres_fdw', 'Foreign tables on other PostgreSQL servers.', ExtensionGroup::BuiltIn),
            new CatalogExtension('refint', 'refint', 'Triggers for referential integrity.', ExtensionGroup::BuiltIn),
            new CatalogExtension('seg', 'seg', 'Line segments and floating-point intervals.', ExtensionGroup::BuiltIn),
            new CatalogExtension('sslinfo', 'sslinfo', 'Information about the client SSL certificate.', ExtensionGroup::BuiltIn),
            new CatalogExtension('tablefunc', 'tablefunc', 'Crosstab and other table functions.', ExtensionGroup::BuiltIn),
            new CatalogExtension('tcn', 'tcn', 'Triggered change notifications.', ExtensionGroup::BuiltIn),
            new CatalogExtension('tsm_system_rows', 'tsm_system_rows', 'TABLESAMPLE by row count.', ExtensionGroup::BuiltIn),
            new CatalogExtension('tsm_system_time', 'tsm_system_time', 'TABLESAMPLE by time limit.', ExtensionGroup::BuiltIn),
            new CatalogExtension('unaccent', 'unaccent', 'Text search dictionary that removes accents.', ExtensionGroup::BuiltIn),
            new CatalogExtension('uuid-ossp', 'uuid-ossp', 'UUID generation functions.', ExtensionGroup::BuiltIn),
            new CatalogExtension('postgis', 'PostGIS', 'Geographic objects, types and functions.', ExtensionGroup::Geo),
            new CatalogExtension('postgis_raster', 'PostGIS Raster', 'Raster types and functions for PostGIS.', ExtensionGroup::Geo),
            new CatalogExtension('postgis_topology', 'PostGIS Topology', 'Topology types and functions for PostGIS.', ExtensionGroup::Geo),
            new CatalogExtension('postgis_sfcgal', 'PostGIS SFCGAL', '3D and advanced geometry functions for PostGIS.', ExtensionGroup::Geo),
            new CatalogExtension('postgis_tiger_geocoder', 'PostGIS Tiger Geocoder', 'US address geocoder for PostGIS.', ExtensionGroup::Geo),
            new CatalogExtension('address_standardizer', 'address_standardizer', 'Parses addresses into their parts.', ExtensionGroup::Geo),
            new CatalogExtension('address_standardizer_data_us', 'address_standardizer_data_us', 'US data for address_standardizer.', ExtensionGroup::Geo),
            new CatalogExtension('pgrouting', 'pgRouting', 'Routing and network analysis on PostGIS.', ExtensionGroup::Geo),
            new CatalogExtension('h3', 'h3', 'Uber H3 hexagonal geospatial index.', ExtensionGroup::Geo),
            new CatalogExtension('h3_postgis', 'h3_postgis', 'H3 and PostGIS integration.', ExtensionGroup::Geo),
            new CatalogExtension('mobilitydb', 'MobilityDB', 'Moving objects and trajectories.', ExtensionGroup::Geo, 'postgis-3'),
            new CatalogExtension('mobilitydb_datagen', 'mobilitydb_datagen', 'Synthetic data for MobilityDB.', ExtensionGroup::Geo, 'postgis-3'),
            new CatalogExtension('pointcloud', 'pointcloud', 'LIDAR point cloud storage.', ExtensionGroup::Geo),
            new CatalogExtension('pointcloud_postgis', 'pointcloud_postgis', 'Point cloud and PostGIS integration.', ExtensionGroup::Geo),
            new CatalogExtension('pg_sphere', 'pg_sphere', 'Spherical geometry types.', ExtensionGroup::Geo),
            new CatalogExtension('q3c', 'q3c', 'Sky indexing for astronomy.', ExtensionGroup::Geo),
            new CatalogExtension('vector', 'pgvector', 'Vector similarity search for embeddings.', ExtensionGroup::Search),
            new CatalogExtension('rum', 'rum', 'RUM index access method for full-text search.', ExtensionGroup::Search),
            new CatalogExtension('pg_textsearch', 'pg_textsearch', 'BM25 relevance-ranked full-text search.', ExtensionGroup::Search, 'pg_textsearch'),
            new CatalogExtension('jsquery', 'jsquery', 'JSON query language with GIN indexes.', ExtensionGroup::Search),
            new CatalogExtension('pgpcre', 'pgpcre', 'Perl-compatible regular expressions.', ExtensionGroup::Search),
            new CatalogExtension('pg_similarity', 'pg_similarity', 'String similarity functions.', ExtensionGroup::Search),
            new CatalogExtension('icu_ext', 'icu_ext', 'ICU library functions.', ExtensionGroup::Search),
            new CatalogExtension('roaringbitmap', 'roaringbitmap', 'Compressed Roaring bitmaps.', ExtensionGroup::Search),
            new CatalogExtension('pgfaceting', 'pgfaceting', 'Faceted search with Roaring bitmaps.', ExtensionGroup::Search),
            new CatalogExtension('timescaledb', 'TimescaleDB', 'Time-series tables and queries.', ExtensionGroup::TimeSeries, 'timescaledb'),
            new CatalogExtension('pg_cron', 'pg_cron', 'Schedules jobs inside the database.', ExtensionGroup::TimeSeries, 'pg_cron', singleSchema: true),
            new CatalogExtension('pg_partman', 'pg_partman', 'Creates and maintains partitions.', ExtensionGroup::TimeSeries),
            new CatalogExtension('pg_background', 'pg_background', 'Runs SQL in background workers.', ExtensionGroup::TimeSeries),
            new CatalogExtension('periods', 'periods', 'SQL periods and system versioning.', ExtensionGroup::TimeSeries),
            new CatalogExtension('table_log', 'table_log', 'Logs table changes and restores to a point in time.', ExtensionGroup::TimeSeries),
            new CatalogExtension('pg_ivm', 'pg_ivm', 'Incrementally maintained materialized views.', ExtensionGroup::TimeSeries),
            new CatalogExtension('hll', 'hll', 'HyperLogLog distinct counts.', ExtensionGroup::DataTypes),
            new CatalogExtension('tdigest', 'tdigest', 't-digest percentile estimates.', ExtensionGroup::DataTypes),
            new CatalogExtension('ip4r', 'ip4r', 'IPv4 and IPv6 range types.', ExtensionGroup::DataTypes),
            new CatalogExtension('unit', 'unit', 'SI units of measure.', ExtensionGroup::DataTypes),
            new CatalogExtension('semver', 'semver', 'Semantic version type.', ExtensionGroup::DataTypes),
            new CatalogExtension('debversion', 'debversion', 'Debian version number type.', ExtensionGroup::DataTypes),
            new CatalogExtension('asn1oid', 'asn1oid', 'ASN.1 OID type.', ExtensionGroup::DataTypes),
            new CatalogExtension('numeral', 'numeral', 'Numbers written as words.', ExtensionGroup::DataTypes),
            new CatalogExtension('pgmp', 'pgmp', 'Arbitrary-precision integers and rationals.', ExtensionGroup::DataTypes),
            new CatalogExtension('pg_rational', 'pg_rational', 'Exact fractions.', ExtensionGroup::DataTypes),
            new CatalogExtension('prefix', 'prefix', 'Prefix range matching, such as phone prefixes.', ExtensionGroup::DataTypes),
            new CatalogExtension('timestamp9', 'timestamp9', 'Nanosecond timestamps.', ExtensionGroup::DataTypes),
            new CatalogExtension('pg_uuidv7', 'pg_uuidv7', 'Time-ordered UUIDv7 values.', ExtensionGroup::DataTypes),
            new CatalogExtension('pg_rrule', 'pg_rrule', 'iCalendar recurrence rules.', ExtensionGroup::DataTypes),
            new CatalogExtension('pg_csv', 'pg_csv', 'CSV parsing and output.', ExtensionGroup::DataTypes),
            new CatalogExtension('first_last_agg', 'first_last_agg', 'first() and last() aggregates.', ExtensionGroup::DataTypes),
            new CatalogExtension('extra_window_functions', 'extra_window_functions', 'More window functions.', ExtensionGroup::DataTypes),
            new CatalogExtension('pg_pwhash', 'pg_pwhash', 'Password hashing with scrypt, argon2 and more.', ExtensionGroup::DataTypes),
            new CatalogExtension('rdkit', 'RDKit', 'Cheminformatics types and functions.', ExtensionGroup::DataTypes),
            new CatalogExtension('age', 'Apache AGE', 'Graph queries with openCypher.', ExtensionGroup::Graph, 'age'),
            new CatalogExtension('mysql_fdw', 'mysql_fdw', 'Foreign tables on MySQL and MariaDB.', ExtensionGroup::ForeignData),
            new CatalogExtension('mongo_fdw', 'mongo_fdw', 'Foreign tables on MongoDB.', ExtensionGroup::ForeignData),
            new CatalogExtension('tds_fdw', 'tds_fdw', 'Foreign tables on SQL Server and Sybase.', ExtensionGroup::ForeignData),
            new CatalogExtension('plproxy', 'PL/Proxy', 'Remote function calls for sharding.', ExtensionGroup::ForeignData),
            new CatalogExtension('pgmemcache', 'pgmemcache', 'Talks to memcached.', ExtensionGroup::ForeignData),
            new CatalogExtension('orafce', 'orafce', 'Oracle-compatible functions and packages.', ExtensionGroup::Compatibility),
            new CatalogExtension('db2fce', 'db2fce', 'DB2-compatible functions.', ExtensionGroup::Compatibility),
            new CatalogExtension('pgtt', 'pgtt', 'Global temporary tables.', ExtensionGroup::Compatibility),
            new CatalogExtension('pg_repack', 'pg_repack', 'Removes bloat with minimal locks.', ExtensionGroup::Operations),
            new CatalogExtension('pg_squeeze', 'pg_squeeze', 'Automatic bloat cleanup. Needs WAL level logical.', ExtensionGroup::Operations, 'pg_squeeze'),
            new CatalogExtension('pg_rewrite', 'pg_rewrite', 'Rewrites tables with less locking.', ExtensionGroup::Operations, 'pg_rewrite'),
            new CatalogExtension('hypopg', 'hypopg', 'Hypothetical indexes.', ExtensionGroup::Operations),
            new CatalogExtension('pg_hint_plan', 'pg_hint_plan', 'Planner hints in SQL comments.', ExtensionGroup::Operations, 'pg_hint_plan'),
            new CatalogExtension('pre_prepare', 'pre_prepare', 'Prepares statements on connect.', ExtensionGroup::Operations, 'pre_prepare'),
            new CatalogExtension('toastinfo', 'toastinfo', 'Shows how values are stored.', ExtensionGroup::Operations),
            new CatalogExtension('pg_permissions', 'pg_permissions', 'Views of every permission.', ExtensionGroup::Operations),
            new CatalogExtension('pg_stat_kcache', 'pg_stat_kcache', 'Per-query CPU and disk statistics.', ExtensionGroup::Observability, 'pg_stat_kcache'),
            new CatalogExtension('pg_qualstats', 'pg_qualstats', 'Statistics of WHERE and JOIN predicates.', ExtensionGroup::Observability, 'pg_qualstats'),
            new CatalogExtension('pg_wait_sampling', 'pg_wait_sampling', 'Wait event sampling.', ExtensionGroup::Observability, 'pg_wait_sampling'),
            new CatalogExtension('pg_stat_plans', 'pg_stat_plans', 'Per-plan statistics and EXPLAIN texts.', ExtensionGroup::Observability, 'pg_stat_plans'),
            new CatalogExtension('pg_show_plans', 'pg_show_plans', 'Plans of the queries running now.', ExtensionGroup::Observability, 'pg_show_plans'),
            new CatalogExtension('pg_stat_log', 'pg_stat_log', 'Counts of log messages.', ExtensionGroup::Observability, 'pg_stat_log'),
            new CatalogExtension('pgsentinel', 'pgsentinel', 'Active session history.', ExtensionGroup::Observability, 'pgsentinel'),
            new CatalogExtension('pg_track_settings', 'pg_track_settings', 'History of configuration changes.', ExtensionGroup::Observability),
            new CatalogExtension('pg_stat_tcpinfo', 'pg_stat_tcpinfo', 'TCP details of client connections.', ExtensionGroup::Observability),
            new CatalogExtension('pg_statviz', 'pg_statviz', 'Snapshots of internal statistics.', ExtensionGroup::Observability),
            new CatalogExtension('pgaudit', 'pgAudit', 'Session and object audit logging.', ExtensionGroup::Audit, 'pgaudit'),
            new CatalogExtension('pgtap', 'pgTAP', 'Unit tests written in SQL.', ExtensionGroup::Development),
            new CatalogExtension('plpgsql_check', 'plpgsql_check', 'Linter and checker for PL/pgSQL.', ExtensionGroup::Development),
            new CatalogExtension('pldbgapi', 'pldbgapi', 'PL/pgSQL debugger API.', ExtensionGroup::Development, 'plugin_debugger'),
            new CatalogExtension('plprofiler', 'plprofiler', 'PL/pgSQL profiler.', ExtensionGroup::Development),
            new CatalogExtension('plperl', 'PL/Perl', 'Trusted Perl functions.', ExtensionGroup::Languages),
            new CatalogExtension('bool_plperl', 'bool_plperl', 'Boolean transform for PL/Perl.', ExtensionGroup::Languages),
            new CatalogExtension('hstore_plperl', 'hstore_plperl', 'hstore transform for PL/Perl.', ExtensionGroup::Languages),
            new CatalogExtension('jsonb_plperl', 'jsonb_plperl', 'jsonb transform for PL/Perl.', ExtensionGroup::Languages),
            new CatalogExtension('pltcl', 'PL/Tcl', 'Trusted Tcl functions.', ExtensionGroup::Languages),
            new CatalogExtension('pllua', 'PL/Lua', 'Trusted Lua functions.', ExtensionGroup::Languages),
            new CatalogExtension('hstore_pllua', 'hstore_pllua', 'hstore transform for PL/Lua.', ExtensionGroup::Languages),
            new CatalogExtension('pljs', 'PL/JS', 'JavaScript functions.', ExtensionGroup::Languages),
        ];
    }

    public static function find(string $name): ?CatalogExtension
    {
        return collect(self::entries())->first(fn (CatalogExtension $entry): bool => $entry->name === $name);
    }

    /**
     * @return list<string>
     */
    public static function offeredFor(Database $database): array
    {
        return array_values(collect(self::entries())
            ->filter(fn (CatalogExtension $entry): bool => $entry->library === null || $database->settings->extensions->has($entry->library))
            ->map(fn (CatalogExtension $entry): string => $entry->name)
            ->all());
    }

    public static function allowlistFor(Database $database): string
    {
        return collect(self::entries())
            ->reject(fn (CatalogExtension $entry): bool => $entry->needsRestart())
            ->map(fn (CatalogExtension $entry): string => $entry->name)
            ->implode(',');
    }
}
