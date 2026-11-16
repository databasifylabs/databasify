<?php

declare(strict_types=1);

use App\Engines\Data\ConfigFile;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Secrets;
use App\Engines\Drivers\MySql\MySqlFiles;
use App\Models\Database;
use App\Models\DatabaseNode;

/**
 * @return list<Member>
 */
function groupMembers(): array
{
    return [
        new Member('10.0.0.11', 0, true, 2, 4096, 80),
        new Member('10.0.0.12', 1, true, 2, 4096, 80),
        new Member('10.0.0.13', 2, false, 2, 4096, 80),
    ];
}

test('a group member allows group connections from every member, not only its own private subnet', function () {
    $request = new NodeConfig(true, false, '10.0.0.11', 'db-1', groupMembers(), [], new Secrets('admin', 'replication', 'agent'), null, null);

    $myCnf = collect(MySqlFiles::of($request, '8.4')->files)->sole(fn (ConfigFile $file): bool => str_ends_with($file->path, '/my.cnf'));

    expect($myCnf->content)->toContain("group_replication_ip_allowlist=10.0.0.11,10.0.0.12,10.0.0.13,127.0.0.1,::1\n");
});

test('a membership change updates the running allowlist with the seeds', function () {
    $database = Database::factory()->engine('mysql', '8.4')->highlyAvailable()->create();
    $node = DatabaseNode::factory()->for($database)->create();

    $sql = $node->driver()->script('engines.mysql.cluster.membership', MySqlFiles::groupSettingsOf(groupMembers()));

    expect($sql)->toContain("SET GLOBAL group_replication_ip_allowlist = '10.0.0.11,10.0.0.12,10.0.0.13,127.0.0.1,::1';")
        ->toContain("SET GLOBAL group_replication_group_seeds = '10.0.0.11:33061,10.0.0.12:33061';");
});
