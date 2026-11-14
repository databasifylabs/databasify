<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\Engines\Data\EngineField;

interface Config
{
    public function apply(): void;

    public function sync(): void;

    /**
     * @return list<string>
     */
    public function pendingRestart(): array;

    /**
     * @return list<EngineField>
     */
    public function fields(): array;

    /**
     * @return list<string>
     */
    public function tune(): array;
}
