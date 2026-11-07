<?php

declare(strict_types=1);

use App\Actions\Databases\Upgrades\RollOutNodes;
use App\Actions\Databases\Upgrades\UpgradeDatabase;
use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeImageRegistry;
use Tests\Support\FakeServerConnection;

function offer(Database $database, string $release, ?string $digest = null): EngineImage
{
    $image = new EngineImage($database->provider()->imageRepository(), $digest ?? FakeImageRegistry::digestFor($database->engine, $release), $release);
    $database->update($image->toAvailableRow());

    return $image;
}

function pullRunsIn(FakeServerConnection $ssh): array
{
    return collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], 'pull_image "$image"') && ! str_contains($run['script'], 'deployed configuration'))->pluck('server')->values()->all();
}

it('rolls an upgrade through read-only, standby, failover and the old primary', function () {
    $database = idleDatabase('valkey', 2);
    $reader = withReadOnlyNode($database);
    $database = withCurrentCertificates($database);
    $target = offer($database, '9.1.2');
    [$primary, $standby] = $database->nodes()->members()->get()->all();
    $database->startOperation('Install Valkey 9.1.2');
    $ssh = fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");
    foreach (range(1, 11) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")
        ->respond()
        ->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    runJob(new UpgradeDatabaseJob($database, installUpdate: true));

    expect(pullRunsIn($ssh))->toBe([$primary->server->name, $standby->server->name, $reader->server->name])
        ->and(collect($ssh->runs)->slice(0, 6)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'deployed configuration')))->toBeEmpty()
        ->and(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'subjectAltName=')))->toBeEmpty()
        ->and($ssh->runs[17]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(17))->toContain('SENTINEL FAILOVER main')
        ->and($database->fresh()->availableImage())->toBeNull()
        ->and($database->fresh()->image())->toEqual($target)
        ->and($database->fresh()->operation)->toBeNull();
});

it('redeploys every node without touching the installed release', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $installed = $database->image();
    offer($database, '9.1.2');
    $ssh = fakeSsh()->respond("role:master\n")->respond()->respond()->respond()->respond()->respond("role:master\n");

    resolve(RollOutNodes::class)->handle($database, installUpdate: false);

    expect(pullRunsIn($ssh))->toBe([])
        ->and(collect($ssh->runs)->pluck('script')->first(fn (string $script): bool => str_contains($script, 'deployed configuration')))->toContain("image='{$installed->reference()}'")
        ->and($database->fresh()->image())->toEqual($installed)
        ->and($database->fresh()->available_image_release)->toBe('9.1.2');
});

it('stops an upgrade at the first node that does not come back', function () {
    $database = idleDatabase('valkey', 2);
    $installed = $database->image();
    offer($database, '9.1.2');
    $database->startOperation('Install update');
    fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond()->respond()->respond()->respond()->respond('timed out waiting', 1);

    expect(fn () => runJob(new UpgradeDatabaseJob($database, installUpdate: true)))->toThrow(RemoteScriptFailed::class)
        ->and($database->fresh()->available_image_release)->toBe('9.1.2')
        ->and($database->fresh()->image())->toEqual($installed)
        ->and($database->fresh()->operation)->toBeNull();
});

it('stops an upgrade when no node is the primary', function () {
    $database = idleDatabase('valkey', 2);
    $database->startOperation('Install update');
    $ssh = fakeSsh()->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    expect(fn () => runJob(new UpgradeDatabaseJob($database, installUpdate: true)))->toThrow(RuntimeException::class, 'main has no healthy primary, rollout stopped.')
        ->and(pullRunsIn($ssh))->toBe([])
        ->and($database->fresh()->operation)->toBeNull();
});

it('pulls the offered image by digest and clears the offer once it is installed', function () {
    $database = withCurrentCertificates(idleDatabase('valkey', 1, DatabaseMode::Single));
    offer($database, '9.1.2', 'sha256:'.str_repeat('f', 64));
    $ssh = fakeSsh()->respond("role:master\n")->respond()->respond()->respond()->respond()->respond()->respond("role:master\n");

    resolve(RollOutNodes::class)->handle($database, installUpdate: true);

    expect($database->fresh()->availableImage())->toBeNull()
        ->and($database->fresh()->image_digest)->toBe('sha256:'.str_repeat('f', 64))
        ->and($ssh->scriptAt(1))->toContain("image='ghcr.io/databasifylabs/databasify-valkey@sha256:".str_repeat('f', 64)."'")
        ->and($ssh->scriptAt(2))->toContain("image='ghcr.io/databasifylabs/databasify-valkey@sha256:".str_repeat('f', 64)."'")
        ->and(fileWrittenIn($ssh->scriptAt(2), '/srv/valkey/conf/processes.json'))->toContain('"name": "databasify-valkey"');
});

it('names the offered release while it pulls its image', function () {
    $database = withCurrentCertificates(idleDatabase('redis', 1, DatabaseMode::Single));
    $target = offer($database, '8.10.3');
    $ssh = fakeSsh()->respond("role:master\n")->respond()->respond()->respond()->respond()->respond()->respond("role:master\n");

    resolve(RollOutNodes::class)->handle($database, installUpdate: true);

    expect($database->fresh()->image_release)->toBe('8.10.3')
        ->and($ssh->scriptAt(1))->toContain("image='{$target->reference()}'")
        ->and($database->serverLogs()->pluck('name'))->toContain("{$database->seed()->server->name}: pulling Redis 8.10.3");
});

it('installs the offered update', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    offer($database, '9.1.2');
    Queue::fake([UpgradeDatabaseJob::class]);

    resolve(UpgradeDatabase::class)->handle($database);

    expect($database->fresh()->operation)->toBe('Install Valkey 9.1.2');
    Queue::assertPushed(UpgradeDatabaseJob::class, fn (UpgradeDatabaseJob $job): bool => $job->database->is($database) && $job->installUpdate);
});

it('names the operation a refresh when the offer keeps the running release', function () {
    withImage('valkey', '9.1.2', 'sha256:'.str_repeat('a', 64));
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    offer($database, '9.1.2', 'sha256:'.str_repeat('b', 64));
    Queue::fake([UpgradeDatabaseJob::class]);

    resolve(UpgradeDatabase::class)->handle($database);

    expect($database->fresh()->operation)->toBe('Refresh the Valkey 9.1.2 image');
    Queue::assertPushed(UpgradeDatabaseJob::class, fn (UpgradeDatabaseJob $job): bool => $job->installUpdate);
});

it('refuses an upgrade when the database already runs the newest release or is busy', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class]);

    expect(fn () => resolve(UpgradeDatabase::class)->handle($database))->toThrow(ValidationException::class, 'main already runs the newest Valkey 9 release.');

    offer($database, '9.1.2');
    $database->startOperation('Back up now');

    expect(fn () => resolve(UpgradeDatabase::class)->handle($database))->toThrow(ValidationException::class, 'is busy with Back up now');
    Queue::assertNothingPushed();
});

it('rolls the upgrade out through the upgrade job', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    offer($database, '9.1.2');
    fakeSsh()->respond("role:master\n")->respond()->respond()->respond()->respond()->respond()->respond("role:master\n");

    resolve(UpgradeDatabase::class)->handle($database);

    expect($database->fresh()->availableImage())->toBeNull()
        ->and($database->fresh()->image_release)->toBe('9.1.2')
        ->and($database->fresh()->operation)->toBeNull();
});

it('installs the offered update over http', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/upgrade"))->assertSessionHasErrors('version');
    Queue::assertNothingPushed();

    offer($database, '9.1.2');
    $this->post(projectUrl("/databases/{$database->id}/upgrade"))->assertRedirect()->assertSessionHasNoErrors();

    Queue::assertPushed(UpgradeDatabaseJob::class, fn (UpgradeDatabaseJob $job): bool => $job->database->is($database) && $job->installUpdate);
});

it('replaces the cluster-wide postgres settings in patroni before restarting the nodes', function () {
    $database = withCurrentCertificates(idleDatabase('postgres', 2));
    $database->update(['settings' => $database->settings->withConfig(['max_connections' => '400'])]);
    [$primary] = $database->nodes()->get()->all();
    $ssh = fakeSsh()->respond("role:primary state:running\n")->respond("role:replica state:streaming\n");

    rescue(fn () => resolve(RollOutNodes::class)->handle($database->fresh(), installUpdate: false), report: false);

    expect($ssh->runs[2]['server'])->toBe($primary->server->name)
        ->and(fileWrittenIn($ssh->scriptAt(2), '/srv/postgres/conf/patroni.yml'))->toContain("max_connections: '400'")
        ->and($ssh->scriptAt(2))->toContain('patroni_ctl edit-config --force --replace "$dir/conf/dcs.yml"')->toContain('print(yaml.safe_dump(dcs))');
});

it('grants the default user its replication rights again on every rollout, so older databases get them', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeSsh();
    foreach (range(1, 20) as $ignored) {
        $ssh->respond("role:primary\nstate:running\n");
    }

    resolve(RollOutNodes::class)->handle($database, installUpdate: false);

    $grant = collect($ssh->runs)->last()['script'] ?? '';
    expect($grant)->toContain('pg_from')->toContain(base64_encode("ALTER ROLE \"{$database->settings->defaultUsername()}\" REPLICATION;\n"));
});

it('finishes the rollout and logs the failure when the default user grant fails', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->startOperation('Redeploy');
    $ssh = fakeSsh();
    foreach (range(1, 9) as $ignored) {
        $ssh->respond("role:primary\nstate:running\n");
    }
    $ssh->respond('ERROR: permission denied', 1);

    runJob(new UpgradeDatabaseJob($database, installUpdate: false));

    expect(collect($ssh->runs)->last()['script'] ?? '')->toContain(base64_encode("ALTER ROLE \"{$database->settings->defaultUsername()}\" REPLICATION;\n"))
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->where('name', 'Grant replication to the default user')->sole())
        ->status->toBe(ServerLogStatus::Failed)
        ->output->toContain('redeploy main to try again')->toContain('failed with exit code 1');
});
