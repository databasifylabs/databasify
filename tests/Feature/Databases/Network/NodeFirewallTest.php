<?php

declare(strict_types=1);

use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Enums\Databases\DatabaseMode;

it('applies the given ranges to one node only', function () {
    $database = idleDatabase('mariadb', 2);
    $node = $database->nodes()->with('server')->get()[1];
    $ssh = fakeSsh();

    resolve(ApplyNodeFirewall::class)->handle($database, $node, ['203.0.113.0/24']);

    expect($ssh->runs)->toHaveCount(1)
        ->and($ssh->runs[0]['server'])->toBe($node->server->name)
        ->and($ssh->scriptAt(0))->toContain('table=databasify_database_'.str_replace('-', '', $database->id))
        ->toContain("elements = { %s } }\\n' '203.0.113.0/24'")
        ->toContain("tcp dport { %s } drop\\n' '4444, 4567, 4568'")->toContain("tcp dport { %s } ip saddr @peers accept\\n' '25060'")
        ->toContain("ip saddr %s tcp dport { %s } accept\\n' \"\$vpc_subnet\" '25060'")
        ->and($database->serverLogs()->sole()->name)->toBe("{$node->server->name}: applying trusted sources");
});

it('removes the database table from one node', function () {
    $database = idleDatabase('redis', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeSsh();

    resolve(RemoveNodeFirewall::class)->handle($database, $node);

    expect($ssh->runs[0]['server'])->toBe($node->server->name)
        ->and($ssh->scriptAt(0))->toContain('table=databasify_database_'.str_replace('-', '', $database->id))->toContain('nft delete table inet "$table"')->not->toContain('set peers');
});

it('opens the database ports in ufw when the server runs it and closes them on removal', function () {
    $database = idleDatabase('mariadb', 2);
    $node = $database->seed();
    $ssh = fakeSsh();

    resolve(ApplyNodeFirewall::class)->handle($database, $node, []);
    resolve(RemoveNodeFirewall::class)->handle($database, $node);

    expect($ssh->scriptAt(0))
        ->toContain("ufw status 2> /dev/null | grep -q '^Status: active'")
        ->toContain("ufw_open \"\$table\" '25060,4444,4567,4568/tcp'")
        ->toContain("firewalld_open \"\$table\" '25060,4444,4567,4568/tcp'")
        ->and($ssh->scriptAt(1))->toContain('ufw_close "$table"')->toContain('firewalld_close "$table"')->not->toContain('ufw_open "$table"')->not->toContain('firewalld_open "$table"');
});
