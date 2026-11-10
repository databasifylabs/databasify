<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\AccountList as AccountListReply;

/**
 * @implements EngineRequest<AccountListReply>
 */
final readonly class AccountList implements EngineRequest
{
    public function command(): string
    {
        return 'account list';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return AccountListReply::class;
    }
}
