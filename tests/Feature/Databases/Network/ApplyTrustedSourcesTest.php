<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\DatabaseNode;

it('applies the trusted ranges on every node including read-only ones', function () {
    $database = idleDatabase('postgres', 2);
    withReadOnlyNode($database);
    $database->trustedSources()->create(['cidr' => '203.0.113.0/24']);
    $database->trustedSources()->create(['cidr' => '198.51.100.7/32']);
    $database->startOperation('Allow 198.51.100.7/32');
    $ssh = fakeSsh();

    runJob(new ApplyTrustedSourcesJob($database));

    expect(collect($ssh->runs)->pluck('server')->all())->toBe($database->nodes()->with('server')->get()->map(fn (DatabaseNode $node): string => $node->server->name)->all())
        ->and(collect($ssh->runs)->pluck('script'))->each->toContain("elements = { %s } }\\n' '203.0.113.0/24, 198.51.100.7/32'")
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->get())->toHaveCount(3)
        ->each(fn ($log) => $log->status->toBe(ServerLogStatus::Succeeded)->name->toEndWith(': applying trusted sources'));
});

it('fails the log of the node that rejects the rules, still applies them on the others and finishes the operation', function () {
    $database = idleDatabase('postgres', 2);
    $database->startOperation('Allow 198.51.100.7/32');
    $ssh = fakeSsh()->respond('nft: syntax error', 1);

    expect(fn () => runJob(new ApplyTrustedSourcesJob($database)))->toThrow(RuntimeException::class)
        ->and($ssh->runs)->toHaveCount(2)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->where('status', ServerLogStatus::Failed)->sole()->output)->toContain('nft: syntax error')
        ->and($database->serverLogs()->where('status', ServerLogStatus::Succeeded)->count())->toBe(1);
});

it('finishes the operation its queued job never finished', function () {
    $database = idleDatabase('postgres', 1);
    $database->startOperation('Allow 198.51.100.7/32');

    (new ApplyTrustedSourcesJob($database))->failed(new RuntimeException('worker timed out'));

    expect($database->fresh()->operation)->toBeNull();
});
