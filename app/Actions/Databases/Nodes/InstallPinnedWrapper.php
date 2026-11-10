<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\EngineClient;
use App\Engines\Requests\HostWrapperUpdate;
use App\Models\DatabaseNode;
use App\Services\Images\WrapperResolver;

final readonly class InstallPinnedWrapper
{
    public function __construct(
        private EngineClient $engine,
        private WrapperResolver $wrappers,
    ) {}

    public function handle(DatabaseNode $node): void
    {
        $wrapper = $this->wrappers->pinned();

        $this->engine->send($node, new HostWrapperUpdate($wrapper->reference()), "{$node->server->name}: installing databasify {$wrapper->version}");
    }
}
