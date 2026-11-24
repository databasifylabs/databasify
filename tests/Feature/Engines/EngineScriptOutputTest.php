<?php

declare(strict_types=1);

use App\Exceptions\Engines\EngineScriptFailed;
use App\Models\Database;
use App\Models\DatabaseNode;

test('a script result the engine cut off is refused with the reason', function () {
    $node = DatabaseNode::factory()->for(Database::factory()->engine('mysql', '8.4'))->create();
    $this->ssh()->replyTo('databasify runtime run', json_encode(['exit' => 0, 'stdout' => 'DATABASIFY_RESULT {"base":[{"Path":"20261124T0200', 'stderr' => '', 'truncated' => true]));

    expect(fn () => $node->driver()->read('engines.mysql-family.archive.status'))
        ->toThrow(EngineScriptFailed::class, 'engines.mysql-family.archive.status printed more than the engine returns, so its result was cut off.');
});
