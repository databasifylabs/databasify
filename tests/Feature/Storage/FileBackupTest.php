<?php

declare(strict_types=1);

use App\Actions\Storage\QueueFileBackup;
use App\Actions\Storage\RunFileBackup;
use App\Enums\Databases\DatabaseStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RunFileBackupJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\FileBackupDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

function fileBackupInput(Database $database): array
{
    return ['name' => 'Offsite backup', 'database_id' => $database->id, 'protocol' => 'sftp', 'host' => 'backup.example.com', 'port' => 22, 'username' => 'backup', 'path' => '/backups', 'host_key' => 'ssh-ed25519 '.base64_encode(str_repeat('a', 32)), 'password' => 'file-server-secret', 'encryption_password' => 'offline-encryption-secret', 'backup_hour' => 3, 'enabled' => true];
}

function createFileBackup(Database $database): FileBackupDestination
{
    $input = fileBackupInput($database);
    $credentials = ['password' => $input['password'], 'encryption_password' => $input['encryption_password']];
    unset($input['password'], $input['encryption_password']);

    return FileBackupDestination::query()->create([...$input, 'project_id' => $database->project_id, 'credentials' => $credentials, 'verified_at' => now(), 'next_backup_at' => now()->subMinute()]);
}

test('a direct sftp destination is tested and stores encrypted credentials', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())->post(route('file-backups.store', $database->project_id), fileBackupInput($database))->assertSessionHasNoErrors();

    $destination = FileBackupDestination::query()->sole();
    expect($destination->verified_at)->not->toBeNull()
        ->and($destination->credentials['password'])->toBe('file-server-secret')
        ->and($destination->getRawOriginal('credentials'))->not->toContain('file-server-secret')
        ->and($this->ssh()->ran('RCLONE_CONFIG_TARGET_KNOWN_HOSTS_FILE'))->toBeTrue();
});

test('failed connection verification does not save a destination', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    $this->ssh()->replyTo('rclone', 'credential material', exit: 1);

    $this->actingAs(User::factory()->create())->post(route('file-backups.store', $database->project_id), fileBackupInput($database))->assertSessionHasErrors('storage');

    expect(FileBackupDestination::query()->count())->toBe(0);
});

test('sftp requires a verified host key and plain ftp requires explicit acknowledgement', function () {
    $database = Database::factory()->create();
    $this->actingAs(User::factory()->create());
    $this->post(route('file-backups.store', $database->project_id), [...fileBackupInput($database), 'host_key' => ''])->assertSessionHasErrors('host_key');
    $this->post(route('file-backups.store', $database->project_id), [...fileBackupInput($database), 'protocol' => 'ftp', 'host_key' => null])->assertSessionHasErrors('confirm_plain_ftp');
    expect($this->ssh()->ranNothing())->toBeTrue();
});

test('cross-project databases and path traversal are rejected', function () {
    $database = Database::factory()->create();
    $other = Database::factory()->for(Project::factory())->create();

    $this->actingAs(User::factory()->create())->post(route('file-backups.store', $database->project_id), [...fileBackupInput($other), 'path' => '/backups/../private'])->assertSessionHasErrors(['database_id', 'path']);
    expect($this->ssh()->ranNothing())->toBeTrue();
});

test('unsupported engines cannot be configured as sql export sources', function () {
    $database = Database::factory()->engine('mongodb', '8.0')->create();
    $this->actingAs(User::factory()->create())->post(route('file-backups.store', $database->project_id), fileBackupInput($database))->assertSessionHasErrors('database_id');
});

test('destination pages never include saved passwords or encryption passphrases', function () {
    $database = Database::factory()->create();
    createFileBackup($database);

    $this->actingAs(User::factory()->create())->get(route('file-backups.index', $database->project_id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('storage/file-backups')->has('destinations', 1)->missing('destinations.0.credentials')->missing('destinations.0.password')->missing('destinations.0.encryption_password'));
});

test('queueing twice does not create overlapping backups', function () {
    Queue::fake([RunFileBackupJob::class]);
    $database = Database::factory()->create();
    $destination = createFileBackup($database);
    $queue = app(QueueFileBackup::class);

    $queue->handle($destination);
    $queue->handle($destination);

    expect($destination->runs()->count())->toBe(1);
    Queue::assertPushed(RunFileBackupJob::class, 1);
});

test('a completed direct backup records the encrypted file and verifies its upload', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    $destination = createFileBackup($database);
    $run = $destination->runs()->create(['status' => 'queued']);

    app(RunFileBackup::class)->handle($run);

    expect($run->refresh()->status)->toBe('succeeded')
        ->and($run->remote_file)->toEndWith('.sql.gz.gpg')
        ->and($this->ssh()->ran('REMOTE_HASH='))->toBeTrue()
        ->and($this->ssh()->ran('pg_dumpall'))->toBeTrue();
});

test('a failed export records failure and emits a generic alert without secrets', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = Database::factory()->create();
    $destination = createFileBackup($database);
    $run = $destination->runs()->create(['status' => 'running']);

    (new RunFileBackupJob($run))->failed(new RuntimeException('private server password'));

    expect($run->refresh()->status)->toBe('failed')->and($run->problem)->not->toContain('private server password');
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
});

test('queued destinations cannot be removed and deleting the database removes local backup records', function () {
    $database = Database::factory()->create();
    $destination = createFileBackup($database);
    $run = $destination->runs()->create(['status' => 'queued']);

    $this->actingAs(User::factory()->create())->delete(route('file-backups.destroy', [$database->project_id, $destination]))->assertSessionHasErrors('storage');
    $database->delete();

    $this->assertModelMissing($destination);
    $this->assertModelMissing($run);
});

test('disabled schedules are skipped while due enabled schedules are queued', function () {
    Queue::fake([RunFileBackupJob::class]);
    $database = Database::factory()->create();
    $enabled = createFileBackup($database);
    $disabled = createFileBackup($database);
    $disabled->update(['enabled' => false]);

    $this->artisan('databases:file-backups')->assertSuccessful();

    expect($enabled->runs()->count())->toBe(1)->and($disabled->runs()->count())->toBe(0);
});

test('busy sources fail before an export starts', function () {
    $database = Database::factory()->status(DatabaseStatus::Updating)->create();
    DatabaseNode::factory()->for($database)->create();
    $destination = createFileBackup($database);
    $run = $destination->runs()->create(['status' => 'queued']);

    expect(fn () => app(RunFileBackup::class)->handle($run))->toThrow(ValidationException::class);
    expect($this->ssh()->ranNothing())->toBeTrue();
});
