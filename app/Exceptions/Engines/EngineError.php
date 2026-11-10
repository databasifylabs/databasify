<?php

declare(strict_types=1);

namespace App\Exceptions\Engines;

use RuntimeException;

class EngineError extends RuntimeException
{
    final public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromReply(string $errorCode, string $message): self
    {
        $exception = match ($errorCode) {
            'invalid_request' => InvalidEngineRequest::class,
            'unsupported' => EngineUnsupported::class,
            'not_found' => EngineNotFound::class,
            'already_exists' => EngineAlreadyExists::class,
            'not_primary' => NotPrimary::class,
            'busy' => EngineBusy::class,
            'unavailable' => EngineUnavailable::class,
            default => self::class,
        };

        return new $exception($errorCode, $message);
    }
}
