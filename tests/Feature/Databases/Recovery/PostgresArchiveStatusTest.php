<?php

declare(strict_types=1);

use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;

function postgresArchiveStatusScript(): string
{
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    markRoles($database, NodeRole::Primary);
    $ssh = fakeSsh();

    resolve(RefreshRecoveryStatus::class)->handle($database->pointInTimeRecovery);

    return $ssh->scriptAt(0);
}

it('keeps the heartbeats with the archive state so turning recovery off clears them', function () {
    expect(postgresArchiveStatusScript())
        ->toContain('heartbeats=/srv/postgres/archive/state/heartbeats')
        ->not->toContain('heartbeats=/srv/postgres/archive/heartbeats');
});

it('ignores heartbeats from before the oldest base backup of this archive', function () {
    expect(postgresArchiveStatusScript())
        ->toContain('-v since="${earliest:-}"')
        ->toContain('since != "" && $1 >= since');
});

it('counts a heartbeat as archived only once its whole segment is, never through a partial or history file', function () {
    expect(postgresArchiveStatusScript())
        ->toContain('named = length(archived) >= 24 && substr(archived, 1, 24) ~ /^[0-9A-F]+$/; whole = named && length(archived) == 24')
        ->toContain('(substr($2, 9) < segment || (whole && substr($2, 9) == segment))')
        ->not->toContain('substr($2, 9) <= substr(archived, 9)');
});

it('creates a missing stanza instead of reporting the WAL that failed before it existed', function () {
    expect(postgresArchiveStatusScript())->toMatch('/if \[ "\$code" = "1" \]; then\n\s+as_postgres pgbackrest .* stanza-create .*\nelse\n\s+problem=\$\(pg "SELECT CASE WHEN last_failed_time/');
});
