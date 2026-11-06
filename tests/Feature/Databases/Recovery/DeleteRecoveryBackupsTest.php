<?php

declare(strict_types=1);

use App\Actions\Databases\Recovery\DeleteArchive;
use App\DTOs\Recovery\StoredArchive;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Models\ServerLog;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  list<list<string>>  $pages
 */
function fakeArchiveBucket(array $pages): void
{
    Http::fake(function (Request $request) use (&$pages) {
        if ($request->method() !== 'GET') {
            return Http::response('<DeleteResult></DeleteResult>');
        }

        $keys = array_shift($pages) ?? [];

        return Http::response('<ListBucketResult>'.implode('', array_map(fn (string $key): string => '<Contents><Key>'.htmlspecialchars($key, ENT_XML1).'</Key></Contents>', $keys)).'</ListBucketResult>');
    });
}

it('deletes every object under the archive prefix page by page', function () {
    $provider = StorageProvider::factory()->create(['path_prefix' => 'team']);
    fakeArchiveBucket([['team/mysql/orders-1/a', 'team/mysql/orders-1/b & c'], ['team/mysql/orders-1/d'], []]);

    expect($provider->s3()->deleteAllUnder('team/mysql/orders-1/'))->toBe(3);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
    expect($requests)->toHaveCount(7)
        ->and($requests[0]->url())->toBe('https://s3.example.com/archives/?list-type=2&max-keys=1000&prefix=team%2Fmysql%2Forders-1%2F')
        ->and($requests[0]->header('Authorization')[0])->toContain('SignedHeaders=host;x-amz-content-sha256;x-amz-date')
        ->and($requests[1]->method())->toBe('POST')
        ->and($requests[1]->url())->toBe('https://s3.example.com/archives/?delete=')
        ->and($requests[1]->body())->toBe('<Delete><Quiet>true</Quiet><Object><Key>team/mysql/orders-1/a</Key></Object><Object><Key>team/mysql/orders-1/b &amp; c</Key></Object></Delete>')
        ->and($requests[1]->header('Content-MD5')[0])->toBe(base64_encode(md5($requests[1]->body(), true)))
        ->and($requests[3]->body())->toContain('team/mysql/orders-1/d')
        ->and($requests[6]->method())->toBe('DELETE')
        ->and($requests[6]->url())->toBe('https://s3.example.com/archives/team/mysql/orders-1/');
});

it('stops when the bucket refuses to delete an object', function () {
    $provider = StorageProvider::factory()->create();
    Http::fake(fn (Request $request) => Http::response($request->method() === 'POST'
        ? '<DeleteResult><Error><Key>mysql/x/a</Key><Code>AccessDenied</Code></Error></DeleteResult>'
        : '<ListBucketResult><Contents><Key>mysql/x/a</Key></Contents></ListBucketResult>'));

    expect(fn () => $provider->s3()->deleteAllUnder('mysql/x/'))->toThrow(RuntimeException::class, 'The bucket refused to delete mysql/x/a: AccessDenied.');
});

it('deletes the backups from the bucket after turning point-in-time recovery off only when asked', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    $path = $database->pointInTimeRecovery?->path;
    Bus::fake();

    $this->delete("/databases/{$database->id}/recovery")->assertSessionHasNoErrors();
    Bus::assertChained([UpgradeDatabaseJob::class]);

    $database->fresh()->finishOperation();
    $database = withRecovery($database);
    $this->delete("/databases/{$database->id}/recovery", ['delete_backups' => true])->assertSessionHasNoErrors();
    Bus::assertChained([
        UpgradeDatabaseJob::class,
        fn (DeleteRecoveryBackupsJob $job): bool => $job->database->is($database) && $job->archive->path === $path && $job->archive->prefix() === "{$path}/",
    ]);
});

it('keeps a folder that still holds other archives', function () {
    $provider = StorageProvider::factory()->create();
    fakeArchiveBucket([['mysql/orders-1/20261027/a'], [], [], ['mysql/orders-1/20261001/a']]);

    resolve(DeleteArchive::class)->handle(new StoredArchive($provider->id, 'mysql/orders-1/20261027'));

    $deletes = Http::recorded()->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $request): bool => $request->method() === 'DELETE')->map(fn (Request $request): string => $request->url())->values()->all();
    expect($deletes)->toBe(['https://s3.example.com/archives/mysql/orders-1/20261027/']);
});

it('deletes the backups under its own operation and finishes it', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'orders');
    $provider = StorageProvider::factory()->create();
    fakeArchiveBucket([['mysql/orders-1/a'], []]);

    dispatch_sync(new DeleteRecoveryBackupsJob($database, new StoredArchive($provider->id, 'mysql/orders-1')));

    expect($database->fresh()->operation)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('deletes the backups while deleting the database only when asked', function (bool $deleteBackups, int $bucketRequests) {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    fakeArchiveBucket([['mysql/orders/a'], []]);
    fakeSsh();

    $this->delete("/databases/{$database->id}", ['erase_data' => true, 'delete_backups' => $deleteBackups])->assertRedirect('/databases');

    expect(Database::query()->exists())->toBeFalse();
    Http::assertSentCount($bucketRequests);
})->with([
    'asked' => [true, 7],
    'not asked' => [false, 0],
]);

it('deletes the database even when the bucket refuses to delete its backups', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    Http::fake(fn () => Http::response('<Error><Code>AccessDenied</Code><Message>nope</Message></Error>', 403));
    fakeSsh();

    $this->delete("/databases/{$database->id}", ['erase_data' => true, 'delete_backups' => true])->assertRedirect('/databases');

    expect(Database::query()->exists())->toBeFalse();
});

it('leaves a foreign operation untouched when busy at start', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'orders');
    $provider = StorageProvider::factory()->create();
    $database->startOperation('Upgrade');
    $log = ServerLog::factory()->for($database->seed()->server)->create(['database_id' => $database->id, 'status' => ServerLogStatus::Running]);
    fakeArchiveBucket([[]]);
    $job = new DeleteRecoveryBackupsJob($database, new StoredArchive($provider->id, 'mysql/orders-1'));

    expect(fn () => dispatch_sync($job))->toThrow(ValidationException::class);

    expect($database->fresh()->operation)->toBe('Upgrade')
        ->and($log->fresh()->status)->toBe(ServerLogStatus::Running);
    Http::assertNothingSent();
});

it('releases its own operation when deletion fails', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'orders');
    $provider = StorageProvider::factory()->create();
    Http::fake(fn () => Http::response('<Error><Code>AccessDenied</Code><Message>nope</Message></Error>', 403));

    expect(fn () => dispatch_sync(new DeleteRecoveryBackupsJob($database, new StoredArchive($provider->id, 'mysql/orders-1'))))->toThrow(RuntimeException::class);

    expect($database->fresh()->operation)->toBeNull();
});
