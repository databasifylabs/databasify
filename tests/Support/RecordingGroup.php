<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\DatabaseNode;

abstract class RecordingGroup
{
    protected string $group;

    public function __construct(private readonly DatabaseNode $node) {}

    /**
     * @param  list<mixed>  $args
     */
    protected function record(string $method, array $args): void
    {
        resolve(EngineRecording::class)->answer("{$this->group}.{$method}", $this->node, $args, returnsValue: false);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function read(string $method, array $args): mixed
    {
        return resolve(EngineRecording::class)->answer("{$this->group}.{$method}", $this->node, $args, returnsValue: true);
    }
}
