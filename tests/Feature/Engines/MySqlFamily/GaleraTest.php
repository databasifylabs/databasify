<?php

declare(strict_types=1);

use App\Engines\Data\Member;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterDisable;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterPosition;
use App\Engines\Requests\ClusterQuorum;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\MemberForget;
use App\Exceptions\Engines\EngineBusy;
use App\Models\DatabaseNode;

beforeEach(function () {
    config(['databasify.php_engines' => ['mariadb']]);
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mariadb', 1, versionLine: '11.8')->nodes()->sole();
    $this->fixture = goFixture('configure-galera-11.8.json', 'mariadb');
    $this->ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($this->fixture['request']));
    respondToFileGet($this->ssh, 'state/tuned.json', null)->respondToEngine('file put', []);
});

function sendToGalera(DatabaseNode $node, object $request): object
{
    return resolve(EngineClient::class)->send($node, $request);
}

it('enables Galera as a task with both manifests', function () {
    sendToGalera($this->node, new ClusterEnable('enable.1'));

    $this->scripts->assertRan('mariadb/cluster/enable', fn (string $script, array $env): bool => base64_decode($env['MANIFEST']) === $this->fixture['manifest']
        && str_contains(base64_decode($env['MANIFEST_NEW_CLUSTER']), '--wsrep-new-cluster')
        && str_contains($script, 'run node configure with clustered on first')
        && str_contains($script, "phase 'bootstrapping'")
        && str_contains($script, 'a cluster needs a primary key on every table; add one to ')
        && str_contains($script, 'the copy from the writer carried no GTID position')
        && str_contains($script, 'MASTER_USE_GTID = slave_pos'));
});

it('leaves the cluster by rewriting the files without clustering', function () {
    sendToGalera($this->node, new ClusterDisable);

    $this->scripts->assertRan('mariadb/cluster/disable', function (string $script, array $env): bool {
        $myCnf = base64_decode(collect(json_decode($env['FILES'], true))->firstWhere('path', '/srv/mariadb/conf/my.cnf')['content']);

        return json_decode($env['REQUEST_JSON'], true)['clustered'] === false
            && ! str_contains($myCnf, 'wsrep_on=ON')
            && str_contains($script, 'RESET SLAVE ALL')
            && str_contains($script, 'unit_do restart mariadb-server');
    });
});

it('moves the writer by recording it on the target over TLS', function () {
    sendToGalera($this->node, new ClusterFailover('10.0.0.3'));

    $this->scripts->assertRan('mariadb/cluster/failover', fn (string $script, array $env): bool => $env['TARGET'] === '10.0.0.3'
        && str_contains($script, 'REPLACE INTO databasify.writer VALUES (1, ')
        && str_contains($script, 'skip-ssl-verify-server-cert')
        && str_contains($script, 'SET GLOBAL read_only = ON'));
});

it('rewrites the files for new members without restarting', function () {
    sendToGalera($this->node, new ClusterMembership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.2', 2, true, 2, 4096, 80)]));

    $this->scripts->assertRan('mariadb/cluster/membership', function (string $script, array $env): bool {
        $myCnf = base64_decode(collect(json_decode($env['FILES'], true))->firstWhere('path', '/srv/mariadb/conf/my.cnf')['content']);

        return str_contains($myCnf, "wsrep_cluster_address=gcomm://10.0.0.1,10.0.0.2\n") && ! str_contains($script, 'unit_do restart');
    });
});

it('forgets a member without touching the node, as the Go engine does', function () {
    sendToGalera($this->node, new MemberForget('10.0.0.2'));

    expect($this->scripts->ran())->toBe([]);
});

it('reads the position from the server, grastate or a recovery run', function () {
    $this->scripts->respond('mariadb/cluster/position', ['position' => 42]);

    expect(sendToGalera($this->node, new ClusterPosition)->position)->toBe(42);
    $this->scripts->assertRan('mariadb/cluster/position', fn (string $script): bool => str_contains($script, 'WSREP_LAST_COMMITTED') && str_contains($script, '--wsrep-recover') && str_contains($script, 'grastate.dat'));
});

it('reads the quorum from the cluster size', function () {
    $this->scripts->respond('mariadb/cluster/quorum', ['total' => 3, 'online' => 3]);

    expect(sendToGalera($this->node, new ClusterQuorum)->online)->toBe(3);
    $this->scripts->assertRan('mariadb/cluster/quorum', fn (string $script): bool => str_contains($script, 'WSREP_CLUSTER_SIZE'));
});

it('forces quorum only once the other nodes are confirmed down', function () {
    expect(fn () => sendToGalera($this->node, new ClusterForceQuorum(false)))
        ->toThrow(EngineBusy::class, 'forcing the cluster while other nodes may be alive would split it; confirm the others are down');

    sendToGalera($this->node, new ClusterForceQuorum(true));

    $this->scripts->assertRan('mariadb/cluster/force-quorum', fn (string $script): bool => str_contains($script, "pc.bootstrap=YES")
        && str_contains($script, 'this node is already in a primary component')
        && str_contains($script, 'safe_to_bootstrap: 1'));
});

it('rejoins as a task', function () {
    sendToGalera($this->node, new ClusterRejoin('rejoin.1', true));

    $this->scripts->assertRan('mariadb/cluster/rejoin', fn (string $script, array $env): bool => $env['READ_ONLY'] === 'true'
        && str_contains($script, "phase 'pointing replication at a synced member'")
        && str_contains($script, "phase 'waiting for Galera to sync'"));
});
