<?php

declare(strict_types=1);

use App\Actions\Databases\Pools\ApplyConnectionPools;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\PoolMode;
use App\Models\User;

it('creates a read-only node pool on that node only', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);

    $this->actingAs(User::factory()->create())
        ->post(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/pools"), ['name' => 'reports', 'schema' => 'defaultdb', 'mode' => 'transaction', 'size' => 10])
        ->assertSessionHasNoErrors();

    expect(collect(recordedArgs('pools.create'))->pluck('server')->all())->toBe([$reader->server->name])
        ->and($reader->connectionPools()->pluck('name')->all())->toBe(['reports'])
        ->and($database->connectionPools()->count())->toBe(0)
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('deletes a read-only node pool from that node only', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $pool = $reader->connectionPools()->create(['database_id' => $database->id, 'name' => 'reports', 'schema' => 'defaultdb', 'mode' => PoolMode::Transaction, 'size' => 10]);
    answerRead('pools.list', [$pool->toPool()]);

    $this->actingAs(User::factory()->create())
        ->delete(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/pools/{$pool->id}"))
        ->assertSessionHasNoErrors();

    expect(collect(recordedArgs('pools.delete'))->pluck('server')->all())->toBe([$reader->server->name])
        ->and($reader->connectionPools()->count())->toBe(0);
});

it('applies the main pools to the primary and standbys only', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'defaultdb', 'size' => 20, 'mode' => PoolMode::Transaction]);

    resolve(ApplyConnectionPools::class)->handle($database);

    expect(collect(recordedArgs('pools.create'))->pluck('server'))->toHaveCount(2)->not->toContain($reader->server->name);
});

it('lets the main and each read-only node use the same pool name', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 1);
    $first = withReadOnlyNode($database);
    $second = withReadOnlyNode($database);
    $first->connectionPools()->create(['database_id' => $database->id, 'name' => 'analytics', 'schema' => 'defaultdb', 'mode' => PoolMode::Transaction, 'size' => 10]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(projectUrl("/databases/{$database->id}/nodes/{$second->id}/pools"), ['name' => 'analytics', 'schema' => 'defaultdb', 'mode' => 'transaction', 'size' => 10])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(projectUrl("/databases/{$database->id}/pools"), ['name' => 'analytics', 'schema' => 'defaultdb', 'mode' => 'transaction', 'size' => 10])->assertSessionHasNoErrors();

    expect($second->connectionPools()->pluck('name')->all())->toBe(['analytics'])
        ->and($database->connectionPools()->pluck('name')->all())->toBe(['analytics']);
});
