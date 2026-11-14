<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\DTOs\Databases\NewTrustedSource;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyNodeTrustedSourcesJob;
use App\Models\DatabaseNode;

final readonly class AddNodeTrustedSource
{
    public function handle(DatabaseNode $node, NewTrustedSource $data): void
    {
        $node->ensureReadOnly();
        $node->database->ensureRunsSupportedSdk();
        $node->markBusyWith(DatabaseStatus::Updating, fn (DatabaseNode $locked) => $locked->trustedSources()->firstOrCreate(['cidr' => $data->cidr], ['database_id' => $locked->database_id, 'label' => $data->label]));
        dispatch(new ApplyNodeTrustedSourcesJob($node));
    }
}
