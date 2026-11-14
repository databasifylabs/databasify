<?php

declare(strict_types=1);

namespace App\Exceptions\Databases;

use App\Models\DatabaseNode;
use RuntimeException;
use Throwable;

final class LastNodeDown extends RuntimeException
{
    public static function after(DatabaseNode $node, Throwable $failure): self
    {
        return new self("{$node->server->name} was set up to run on its own but did not come back: {$failure->getMessage()} Check its engine log before you retry.", previous: $failure);
    }
}
