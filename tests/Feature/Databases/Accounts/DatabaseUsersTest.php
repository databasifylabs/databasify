<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates, resets and deletes users over http', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    idleDatabase('mysql', 1, DatabaseMode::Single, 'other')->users()->create(['name' => 'reporting', 'password' => 'secret']);
    fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting'])->assertRedirect()->assertSessionHasNoErrors();
    $user = $database->users()->where('name', 'reporting')->sole();
    $created = $user->password;

    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/password"))->assertRedirect()->assertSessionHasNoErrors();
    $reset = $user->fresh()->password;

    $this->delete(projectUrl("/databases/{$database->id}/users/{$user->id}"))->assertRedirect()->assertSessionHasNoErrors();

    $create = recordedArgs('accounts.createUser')[0]['args'];
    expect([$create[0], $create[1], $create[3]])->toBe(['reporting', $created, 'caching_sha2_password'])
        ->and(recordedArgs('accounts.setPassword')[0]['args'])->toBe(['reporting', $reset, 'caching_sha2_password'])
        ->and($reset)->not->toBe($created)
        ->and(recordedArgs('accounts.dropUser')[0]['args'])->toBe(['reporting'])
        ->and($database->users()->pluck('name')->all())->toBe(['databasify']);
});

it('creates a legacy user and switches its password encryption over http', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting', 'password_encryption' => 'mysql_native_password'])->assertRedirect()->assertSessionHasNoErrors();
    $user = $database->users()->where('name', 'reporting')->sole();
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/encryption"), ['password_encryption' => 'caching_sha2_password'])->assertRedirect()->assertSessionHasNoErrors();

    expect(recordedArgs('accounts.createUser')[0]['args'][3])->toBe('mysql_native_password')
        ->and(recordedArgs('accounts.setPassword')[0]['args'])->toBe(['reporting', $user->password, 'caching_sha2_password'])
        ->and($user->fresh()->password_encryption)->toBe('caching_sha2_password');
});

it('rejects an unknown password encryption', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $user = $database->users()->sole();
    $ssh = fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting', 'password_encryption' => 'sha256_password'])->assertSessionHasErrors('password_encryption');
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/encryption"), ['password_encryption' => 'plain'])->assertSessionHasErrors('password_encryption');

    expect($ssh->runs)->toBe([]);
});

it('rejects user names that are invalid, reserved or taken', function (string $engine, mixed $name) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => $name])->assertSessionHasErrors('name');

    expect($ssh->runs)->toBe([])
        ->and($database->users()->pluck('name')->all())->toBe(['databasify']);
})->with([
    'missing' => ['mysql', ''],
    'leading digit' => ['mysql', '1reporting'],
    'dash' => ['mysql', 'report-ing'],
    'too long' => ['mysql', str_repeat('a', 33)],
    'taken' => ['mysql', 'databasify'],
    'mysql reserved' => ['mysql', 'root'],
    'mariadb reserved' => ['mariadb', 'databasify_sst'],
    'postgres reserved' => ['postgres', 'pgbouncer'],
]);

it('refuses users on sentinel databases over http', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting'])->assertSessionHasErrors('database');

    expect($database->users()->count())->toBe(0);
})->with(['valkey', 'redis']);

it('only touches users of the database in the url', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $foreign = idleDatabase('mysql', 1, DatabaseMode::Single, 'other')->users()->sole();
    $ssh = fakeEngine();

    $this->post(projectUrl("/databases/{$database->id}/users/{$foreign->id}/password"))->assertNotFound();
    $this->delete(projectUrl("/databases/{$database->id}/users/{$foreign->id}"))->assertNotFound();

    expect($ssh->runs)->toBe([])
        ->and($foreign->fresh())->not->toBeNull();
});

it('refuses to delete the default user over http but still resets its password', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->sole();
    fakeEngine();

    $this->delete(projectUrl("/databases/{$database->id}/users/{$user->id}"))->assertSessionHasErrors(['user' => "databasify is the default user and can't be deleted."]);
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/password"))->assertRedirect()->assertSessionHasNoErrors();

    expect(recordedCalls())->toBe(['accounts.setPassword'])
        ->and($database->users()->pluck('name')->all())->toBe(['databasify']);
});

it('flags the default user and database on the database page', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->users()->create(['name' => 'reporting', 'password' => 'secret']);
    $database->schemas()->create(['name' => 'analytics']);

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.users.0.name', 'databasify')->where('database.data.users.0.is_default', true)
        ->where('database.data.users.1.name', 'reporting')->where('database.data.users.1.is_default', false)
        ->where('database.data.schemas.0.name', 'analytics')->where('database.data.schemas.0.is_default', false)
        ->where('database.data.schemas.1.name', 'defaultdb')->where('database.data.schemas.1.is_default', true));
});
