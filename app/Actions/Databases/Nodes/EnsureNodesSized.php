<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Exceptions\Databases\ServerSizeUnknown;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class EnsureNodesSized
{
    public function __construct(private BuildNodeRequest $nodeRequest) {}

    public function handle(Database $database, ?DatabaseNode $without = null): void
    {
        try {
            $this->nodeRequest->membersOf($database, $without);
        } catch (ServerSizeUnknown $unknown) {
            throw ValidationException::withMessages(['server_id' => $unknown->getMessage()]);
        }
    }
}
