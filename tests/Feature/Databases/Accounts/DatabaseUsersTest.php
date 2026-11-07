<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PasswordEncryption;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates, resets and deletes users over http', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    idleDatabase('mysql', 1, DatabaseMode::Single, 'other')->users()->create(['name' => 'reporting', 'password' => 'secret']);
    $ssh = fakeSsh();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting'])->assertRedirect()->assertSessionHasNoErrors();
    $user = $database->users()->where('name', 'reporting')->sole();
    $created = $user->password;

    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/password"))->assertRedirect()->assertSessionHasNoErrors();
    $reset = $user->fresh()->password;

    $this->delete(projectUrl("/databases/{$database->id}/users/{$user->id}"))->assertRedirect()->assertSessionHasNoErrors();

    expect(sqlSentIn($ssh->scriptAt(0)))->toStartWith("CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED WITH caching_sha2_password BY '{$created}'")
        ->and(sqlSentIn($ssh->scriptAt(1)))->toBe("ALTER USER 'reporting'@'%' IDENTIFIED WITH caching_sha2_password BY '{$reset}';\n")
        ->and($reset)->not->toBe($created)
        ->and(sqlSentIn($ssh->scriptAt(2)))->toBe("DROP USER IF EXISTS 'reporting'@'%';\n")
        ->and($database->users()->pluck('name')->all())->toBe(['databasify']);
});

it('creates a legacy user and switches its password encryption over http', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting', 'password_encryption' => 'mysql_native_password'])->assertRedirect()->assertSessionHasNoErrors();
    $user = $database->users()->where('name', 'reporting')->sole();
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/encryption"), ['password_encryption' => 'caching_sha2_password'])->assertRedirect()->assertSessionHasNoErrors();

    expect(sqlSentIn($ssh->scriptAt(0)))->toStartWith("CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED WITH mysql_native_password BY '{$user->password}'")
        ->and(sqlSentIn($ssh->scriptAt(1)))->toBe("ALTER USER 'reporting'@'%' IDENTIFIED WITH caching_sha2_password BY '{$user->password}';\n")
        ->and($user->fresh()->password_encryption)->toBe(PasswordEncryption::Default);
});

it('offers mysql 9.7 no legacy password encryption, since the server removed mysql_native_password', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, versionLine: '9.7');
    $user = $database->users()->sole();
    $ssh = fakeSsh();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting', 'password_encryption' => 'mysql_native_password'])->assertSessionHasErrors('password_encryption');
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/encryption"), ['password_encryption' => 'mysql_native_password'])->assertSessionHasErrors('password_encryption');

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page->where('database.data.password_encryptions', []));
    $this->get(projectUrl('/databases/').idleDatabase('mysql', 1, DatabaseMode::Single, 'legacy')->id)->assertInertia(fn (AssertableInertia $page) => $page->where('database.data.password_encryptions.1.value', 'mysql_native_password'));

    expect($ssh->runs)->toBe([])
        ->and($user->fresh()->password_encryption)->toBe(PasswordEncryption::Default);
});

it('rejects an unknown password encryption', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $user = $database->users()->sole();
    $ssh = fakeSsh();

    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'reporting', 'password_encryption' => 'sha256_password'])->assertSessionHasErrors('password_encryption');
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/encryption"), ['password_encryption' => 'plain'])->assertSessionHasErrors('password_encryption');

    expect($ssh->runs)->toBe([]);
});

it('rejects user names that are invalid, reserved or taken', function (string $engine, mixed $name) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh();

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
    'mariadb reserved' => ['mariadb', 'sst'],
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
    $ssh = fakeSsh();

    $this->post(projectUrl("/databases/{$database->id}/users/{$foreign->id}/password"))->assertNotFound();
    $this->delete(projectUrl("/databases/{$database->id}/users/{$foreign->id}"))->assertNotFound();

    expect($ssh->runs)->toBe([])
        ->and($foreign->fresh())->not->toBeNull();
});

it('refuses to delete the default user over http but still resets its password', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->sole();
    $ssh = fakeSsh();

    $this->delete(projectUrl("/databases/{$database->id}/users/{$user->id}"))->assertSessionHasErrors(['user' => "databasify is the default user and can't be deleted."]);
    $this->post(projectUrl("/databases/{$database->id}/users/{$user->id}/password"))->assertRedirect()->assertSessionHasNoErrors();

    expect($ssh->runs)->toHaveCount(1)
        ->and(sqlSentIn($ssh->scriptAt(0)))->toStartWith("ALTER USER 'databasify'@'%'")
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
