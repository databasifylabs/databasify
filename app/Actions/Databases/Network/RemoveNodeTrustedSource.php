<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyNodeTrustedSourcesJob;
use App\Models\DatabaseNode;
use App\Models\TrustedSource;

final readonly class RemoveNodeTrustedSource
{
    public function handle(DatabaseNode $node, TrustedSource $source): void
    {
        $node->ensureReadOnly();
        $node->database->ensureRunsSupportedSdk();
        $node->markBusyWith(DatabaseStatus::Updating, fn (): ?bool => $source->delete());
        dispatch(new ApplyNodeTrustedSourcesJob($node));
    }
}
