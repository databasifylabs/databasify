<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Enums\Databases\GrantAccess;

final readonly class Grant
{
    public function __construct(
        public string $database,
        public GrantAccess $access,
    ) {}

    /**
     * @return array{database: string, access: string}
     */
    public function toPayload(): array
    {
        return ['database' => $this->database, 'access' => $this->access->value];
    }
}
