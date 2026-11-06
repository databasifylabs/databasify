<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\StartMigration;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationPhase;
use App\Jobs\Databases\RunMigrationJob;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->ssh = fakeSsh();
    $this->database = migratingDatabase('postgres');
    $this->migration = $this->database->provider()->migration();
});

it('asks for credentials and protects the template databases', function () {
    expect($this->migration->form()->defaultPort)->toBe(5432)
        ->and($this->migration->form()->rules()['databases.*'])->toContain('not_in:postgres,template0,template1');
});

it('checks the source with encoded credentials and the chosen ssl mode', function () {
    $ssh = $this->ssh
        ->respond("reachable=yes\nversion=9.6.24\nwal_level=logical\ncan_replicate=t\nsize_mb=3\ndatabases=legacy\n")
        ->respond("reachable=no\nerror=psql: error: connection refused\n");

    $old = $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'top-secret', false));
    $refused = $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'secret'));

    expect($old->blockers)->toBe(['Continuous sync needs PostgreSQL 10 or newer on the source.'])
        ->and($old->facts)->toBe(['version' => '9.6.24', 'size_mb' => '3', 'wal_level' => 'logical', 'databases' => 'legacy'])
        ->and($ssh->scriptAt(0))->toContain("SOURCE_SSLMODE='prefer'")->toContain(base64_encode('top-secret'))->not->toContain('top-secret')
        ->and($ssh->scriptAt(1))->toContain("SOURCE_SSLMODE='require'")
        ->and($refused->error)->toBe('psql: error: connection refused');
});

it('refuses a continuous migration when wal_level is not logical or the user cannot replicate', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond("reachable=yes\nversion=17.2\nwal_level=replica\ncan_replicate=f\n");

    expect(fn () => resolve(StartMigration::class)->handle($this->database, new MigrationSource('pg.example.com', 5432, 'migrator', 'secret'), MigrationMode::Continuous))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['mode'])->toBe([
            'Continuous sync is not possible with this source:',
            'Set wal_level to logical on the source and restart it.',
            'The source user needs the REPLICATION attribute. Grant the REPLICATION attribute to migrator on the source.',
        ]));
    Queue::assertNothingPushed();
});

it('refuses a continuous migration when other roles own tables it would publish', function () {
    $this->ssh->respond("reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\nunowned=shop.public.orders shop.audit.log\n");

    $report = $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'secret'));

    expect($report->blockers)->toBe(['Continuous sync publishes every table, so the source user must own them or be a superuser: shop.public.orders, shop.audit.log.'])
        ->and($report->problems)->toBe([]);
});

it('refuses a one-time migration when the source user cannot read tables owned by other roles', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond("reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\nunreadable=shop.public.orders\n");

    expect(fn () => resolve(StartMigration::class)->handle($this->database, new MigrationSource('pg.example.com', 5432, 'migrator', 'secret', true, ['shop']), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'The source user cannot read these tables, so they cannot be copied: shop.public.orders. Grant it SELECT on them and USAGE on their schemas, or migrate as their owner.');
    Queue::assertNothingPushed();
});

it('names at most ten tables in a refusal', function () {
    $tables = collect(range(1, 12))->map(fn (int $number): string => "shop.public.t{$number}");
    $this->ssh->respond("reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\nunreadable={$tables->implode(' ')}\n");

    $report = $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'secret'));

    expect($report->problems[0] ?? '')->toContain(': '.$tables->take(10)->implode(', ').' and 2 more.');
});

it('looks for tables the source user cannot read or does not own in every database it copies', function () {
    $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'secret', true, ['shop']));

    expect($this->ssh->scriptAt(0))->toContain('NOT (has_schema_privilege(n.oid, \'USAGE\') AND has_table_privilege(c.oid, \'SELECT\'))')
        ->toContain('NOT pg_has_role(c.relowner, \'USAGE\')')
        ->toContain("printf 'unreadable=%s\\n'")
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});

it('restores as the superuser so extensions install, then gives the copied objects to the default user', function () {
    $this->migration->start(migrationOf($this->database, MigrationMode::OneTime));

    expect($this->ssh->scriptAt(0))->toContain("MIGRATION_OWNER='databasify'")
        ->toContain('GRANT ALL PRIVILEGES ON DATABASE \\"$db\\" TO \\"$MIGRATION_OWNER\\"')
        ->toContain('GRANT ALL ON SCHEMA public TO \\"$MIGRATION_OWNER\\"')
        ->toContain('pg_restore -U postgres -d "$db" --no-owner --no-acl --exit-on-error')->not->toContain('--role=')
        ->toContain('own_restored "$db"')
        ->toContain("SELECT format('ALTER %s %s OWNER TO %I'")
        ->toContain("d.deptype = 'e'")
        ->toContain("AND NOT (c.relkind = 'S' AND EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')))")
        ->toContain("SELECT format('ALTER LARGE OBJECT %s OWNER TO %I', l.oid, :'owner')")
        ->toContain('\\gexec')->not->toContain('DO $own$')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});

it('copies with subscriptions only for continuous migrations', function () {
    $continuous = migrationOf($this->database, MigrationMode::Continuous);
    $continuous->update(['source' => new MigrationSource('pg.example.com', 5432, 'migrator', 'secret', true, ['shop', 'blog'])]);
    $oneTime = migrationOf($this->database, MigrationMode::OneTime);
    $ssh = $this->ssh;

    $this->migration->start($continuous->fresh() ?? $continuous);
    $this->migration->start($oneTime);

    expect($ssh->scriptAt(0))->toContain("MIGRATION_DATABASES='shop blog'")->toContain("MIGRATION_CONTINUOUS='true'")->toContain('CREATE SUBSCRIPTION')
        ->and($ssh->scriptAt(1))->toContain("MIGRATION_DATABASES=''")->toContain("MIGRATION_CONTINUOUS='false'")->toContain('echo "copying from db.example.com:3306 in the background"');
});

it('follows subscriptions until every table is ready and recent', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $this->ssh
        ->respond("copy=running\nlog=copying shop\n")
        ->respond("copy=lost\nlog=\n")
        ->respond("copy_exit=1\nlog=pg_restore: error\n")
        ->respond("copy_exit=0\nlog=shop done\nsubscribed_tables=4\nready_tables=2\nlags=0\n")
        ->respond("copy_exit=0\nsubscribed_tables=4\nready_tables=4\nlags=\n")
        ->respond("copy_exit=0\nsubscribed_tables=4\nready_tables=4\nlags=0 3\n");

    $progress = collect(range(1, 6))->map(fn () => $this->migration->progress($migration));

    expect($progress[0])->phase->toBe(MigrationPhase::Working)->detail->toBe('Copying: copying shop')
        ->and($progress[1])->phase->toBe(MigrationPhase::Broken)
        ->and($progress[2])->phase->toBe(MigrationPhase::Broken)->detail->toBe('The copy failed: pg_restore: error')
        ->and($progress[3])->phase->toBe(MigrationPhase::Working)->detail->toBe('Copying tables: 2 of 4 ready.')
        ->and($progress[4])->phase->toBe(MigrationPhase::Stalled)->detail->toBe('The subscription is reconnecting to the source.')
        ->and($progress[5])->phase->toBe(MigrationPhase::CaughtUp)->detail->toBe('Replicating, 3s behind the source.')->lagSeconds->toBe(3);
});

it('reports no lag once the source says the subscriber has everything', function () {
    $this->migration->progress(migrationOf($this->database, MigrationMode::Continuous));

    expect($this->ssh->scriptAt(0))->toContain("CASE WHEN st.last_msg_send_time IS NULL THEN NULL WHEN st.latest_end_lsn >= st.received_lsn AND now() - st.last_msg_receipt_time < interval '90 seconds' THEN 0")
        ->toContain('COALESCE((CASE')->toContain("'unknown'")
        ->toContain('FROM pg_subscription s LEFT JOIN pg_stat_subscription st');
});

it('stalls while any subscription has no running apply worker', function () {
    $this->ssh->respond("copy_exit=0\nsubscribed_tables=4\nready_tables=4\nlags=0 unknown\n");

    expect($this->migration->progress(migrationOf($this->database, MigrationMode::Continuous)))
        ->phase->toBe(MigrationPhase::Stalled)->detail->toBe('The subscription is reconnecting to the source.')->lagSeconds->toBeNull();
});

it('catches up a continuous copy of databases without tables to follow', function () {
    $this->ssh->respond("copy_exit=0\nlog=shop has no tables to replicate shop done\nlags=\n");

    expect($this->migration->progress(migrationOf($this->database, MigrationMode::Continuous)))
        ->phase->toBe(MigrationPhase::CaughtUp)->detail->toBe('Copied every database, none of them has tables to follow.')->lagSeconds->toBe(0);
});

it('finishes one-time copies once the dump exits cleanly', function () {
    $this->ssh->respond("copy_exit=0\nlog=shop done\n");

    expect($this->migration->progress(migrationOf($this->database, MigrationMode::OneTime)))
        ->phase->toBe(MigrationPhase::CaughtUp)->detail->toBe('Copied every database from the source.');
});

it('needs no extra step to follow and drops subscriptions when stopping', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $ssh = $this->ssh;

    $this->migration->follow($migration);
    expect($ssh->runs)->toBe([]);

    $this->migration->stop($migration);
    expect($ssh->scriptAt(0))->toContain('state=/srv/postgres/migration')->toContain('copy_sequences "$db" "$name"')->toContain('DROP SUBSCRIPTION');
});

it('copies and checks only the source databases whose names Databasify can manage when none were selected', function () {
    $this->migration->start(migrationOf($this->database, MigrationMode::OneTime));
    $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'secret'));

    expect($this->ssh->scriptAt(1))->toContain("ORDER BY datname\" | { grep -Ex '^[A-Za-z][A-Za-z0-9_]*$' || true; } | paste -sd ' ' -)")
        ->and(bashErrorsIn($this->ssh->scriptAt(1)))->toBe('');

    expect($this->ssh->scriptAt(0))->toContain("--setenv=SCHEMA_NAME='^[A-Za-z][A-Za-z0-9_]*$'")
        ->toContain('databases=$(printf \'%s\n\' "$listed" | { grep -Ex "$SCHEMA_NAME" || true; } | paste -sd \' \' -)')
        ->toContain('skipped=$(printf \'%s\n\' "$listed" | { grep -Evx "$SCHEMA_NAME" || true; } | paste -sd \' \' -)')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});

it('names the publication and subscriptions after the target so two targets of one source keep their own', function () {
    $other = idleDatabase('postgres', 1, DatabaseMode::Single, 'ledger');
    $other->seed()->update(['role' => NodeRole::Primary]);
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $otherMigration = migrationOf($other, MigrationMode::Continuous);

    $this->migration->start($migration);
    $other->provider()->migration()?->start($otherMigration);

    $publication = 'databasify_migration_'.substr(str_replace('-', '', $this->database->id), -12);
    $otherPublication = 'databasify_migration_'.substr(str_replace('-', '', $other->id), -12);

    expect($publication)->not->toBe($otherPublication)
        ->and($this->ssh->scriptAt(0))->toContain("--setenv=MIGRATION_PUBLICATION='{$publication}'")
        ->toContain('-Xqc "DROP PUBLICATION IF EXISTS \\"$MIGRATION_PUBLICATION\\"" -c "CREATE PUBLICATION \\"$MIGRATION_PUBLICATION\\" FOR TABLE $tables"')
        ->toContain('-v publication="$MIGRATION_PUBLICATION" -v db="$db"')
        ->toContain('PUBLICATION :"publication" WITH (copy_data = true);')
        ->and($this->ssh->scriptAt(1))->toContain("--setenv=MIGRATION_PUBLICATION='{$otherPublication}'")
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});

it('drops the publication each subscription follows when stopping, including ones named before the target id', function () {
    $this->migration->stop(migrationOf($this->database, MigrationMode::Continuous));

    expect($this->ssh->scriptAt(0))->toContain("array_to_string(s.subpublications, ',')")
        ->toContain('in_pg psql "$publisher connect_timeout=10" -Xqc "DROP PUBLICATION IF EXISTS \\"$publication\\""')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});

it('checks a source that holds no database Databasify can manage without failing', function () {
    $this->migration->check(new MigrationSource('pg.example.com', 5432, 'migrator', 'secret'));
    preg_match('/^\[ -n "\$wanted" \] \|\| wanted=.*$/m', $this->ssh->scriptAt(0), $line);

    $result = Process::input("set -euo pipefail\nsource_query() { printf 'my-app\\n'; }\nwanted=''\n{$line[0]}\necho \"wanted=[\$wanted]\"\n")->run(['bash']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("wanted=[]\n");
});

it('keeps subscription names unique within the postgres name limit for long database names', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);

    $this->migration->start($migration);

    expect($this->ssh->scriptAt(0))->toContain('-v publication="$MIGRATION_PUBLICATION" -v db="$db"')
        ->toContain("SELECT :'publication' || '_' || left(:'db', 20) || '_' || left(md5(:'db'), 8) AS name \\gset")
        ->toContain('CREATE SUBSCRIPTION :"name" CONNECTION :\'conninfo\' PUBLICATION :"publication" WITH (copy_data = true);')
        ->and(strlen('databasify_migration_'.str_repeat('a', 12).'_'.str_repeat('b', 20).'_'.str_repeat('c', 8)))->toBe(63)
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});
