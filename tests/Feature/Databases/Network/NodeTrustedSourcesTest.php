<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\User;

it('applies a read-only node trusted source on that node only', function () {
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    $ssh = fakeSsh();

    $this->actingAs(User::factory()->create())
        ->post(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/trusted-sources"), ['cidr' => '198.51.100.7/32'])
        ->assertSessionHasNoErrors();

    expect(collect($ssh->runs)->pluck('server')->all())->toBe([$reader->server->name])
        ->and(collect($ssh->runs)->pluck('script')->first())->toContain('198.51.100.7/32')
        ->and($reader->trustedSources()->pluck('cidr')->all())->toBe(['198.51.100.7/32'])
        ->and($database->trustedSources()->count())->toBe(0)
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($database->fresh()?->isBusy())->toBeFalse();
});

it('removes a read-only node trusted source from that node only', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $source = $reader->trustedSources()->create(['database_id' => $database->id, 'cidr' => '198.51.100.7/32']);
    $ssh = fakeSsh();

    $this->actingAs(User::factory()->create())
        ->delete(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/trusted-sources/{$source->id}"))
        ->assertSessionHasNoErrors();

    expect($reader->trustedSources()->count())->toBe(0)
        ->and(collect($ssh->runs)->pluck('server')->all())->toBe([$reader->server->name]);
});

it('applies the main trusted sources to the primary and standbys only', function () {
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    $database->update(['status' => DatabaseStatus::Updating]);
    $ssh = fakeSsh();

    runJob(new ApplyTrustedSourcesJob($database));

    expect(collect($ssh->runs)->pluck('server'))->not->toContain($reader->server->name)
        ->and(collect($ssh->runs))->toHaveCount(2);
});

it('refuses own trusted sources on a member node', function () {
    $database = idleDatabase('postgres', 1);
    $member = $database->seed();

    $this->actingAs(User::factory()->create())
        ->post(projectUrl("/databases/{$database->id}/nodes/{$member->id}/trusted-sources"), ['cidr' => '198.51.100.7/32'])
        ->assertSessionHasErrors('database');

    expect($member->trustedSources()->count())->toBe(0);
});
