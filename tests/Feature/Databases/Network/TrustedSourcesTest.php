<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('validates trusted sources over http', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/trusted-sources"), ['cidr' => '10.0.0.0/33'])->assertSessionHasErrors('cidr');
    $this->post(projectUrl("/databases/{$database->id}/trusted-sources"), ['cidr' => '198.51.100.0/24'])->assertSessionHasNoErrors();

    $source = $database->trustedSources()->sole();
    $database->fresh()->finishOperation();
    $this->delete(projectUrl("/databases/{$database->id}/trusted-sources/{$source->id}"))->assertSessionHasNoErrors();

    expect($database->trustedSources()->count())->toBe(0);
    Queue::assertPushed(ApplyTrustedSourcesJob::class, 2);
});

it('stores a bare address as a single host and applies it on the nodes straight away', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    $this->post(projectUrl("/databases/{$database->id}/trusted-sources"), ['cidr' => ' 203.0.113.7 ', 'label' => 'office'])->assertRedirect()->assertSessionHasNoErrors();

    expect($database->trustedSources()->sole())
        ->cidr->toBe('203.0.113.7/32')
        ->label->toBe('office')
        ->and($ssh->scriptAt(0))->toContain("elements = { %s } }\\n' '203.0.113.7/32'")->toContain("tcp dport { %s } drop\\n' '25060, 25061'")
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->sole())
        ->name->toBe("{$database->seed()->server->name}: applying trusted sources")
        ->status->toBe(ServerLogStatus::Succeeded);
});

it('rejects trusted sources that are not ipv4 ranges', function (array $input, string $field) {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/trusted-sources"), ['cidr' => '203.0.113.0/24', ...$input])->assertSessionHasErrors($field);

    expect($database->trustedSources()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing' => [['cidr' => ''], 'cidr'],
    'ipv6' => [['cidr' => '2001:db8::/32'], 'cidr'],
    'hostname' => [['cidr' => 'office.example.com'], 'cidr'],
    'zero prefix' => [['cidr' => '0.0.0.0/0'], 'cidr'],
    'non numeric prefix' => [['cidr' => '203.0.113.0/x'], 'cidr'],
    'bad octet' => [['cidr' => '203.0.113.256'], 'cidr'],
    'long label' => [['label' => str_repeat('a', 61)], 'label'],
]);

it('only removes trusted sources of the database in the url', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $foreign = idleDatabase('mysql', 1, DatabaseMode::Single, 'other')->trustedSources()->create(['cidr' => '203.0.113.0/24']);
    Queue::fake([ApplyTrustedSourcesJob::class]);

    $this->delete(projectUrl("/databases/{$database->id}/trusted-sources/{$foreign->id}"))->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});
