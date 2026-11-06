<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\DetectQuorum;
use App\Enums\Databases\NodeRole;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as RenderedView;

it('finds a majority as soon as one reachable member sees more than half online', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Unknown);
    $primary = $database->nodes()->get()[1];
    $ssh = fakeSsh()->respond("total=3\nonline=2\n");

    expect(resolve(DetectQuorum::class)->handle($database))->toBeTrue()
        ->and($ssh->runs)->toHaveCount(1)
        ->and($ssh->runs[0]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(0))->toContain('replication_group_members');
});

it('sees no majority when members report half or less or cannot answer', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Unknown);
    $ssh = fakeSsh()->respond("total=4\nonline=2\n")->respond('', 1);

    expect(resolve(DetectQuorum::class)->handle($database))->toBeFalse()
        ->and($ssh->runs)->toHaveCount(2);

    $ssh->respond("total=0\nonline=0\n")->respond('garbage');

    expect(resolve(DetectQuorum::class)->handle($database))->toBeFalse();
});

it('hands the quorum script the node it runs on', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    $node = $database->nodes()->get()[0];
    $data = [];
    View::composer($database->provider()->scriptView('cluster.quorum'), function (RenderedView $view) use (&$data): void {
        $data = $view->getData();
    });
    fakeSsh()->respond("total=3\nonline=3\n");

    resolve(DetectQuorum::class)->handle($database);

    expect($data)->toMatchArray(['privateIp' => $node->privateIp(), 'clustered' => true, 'directory' => '/srv/mysql']);
});
