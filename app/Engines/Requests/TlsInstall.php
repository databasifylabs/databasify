<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class TlsInstall implements EngineRequest
{
    public function __construct(
        public string $certificate,
        public string $key,
        public string $ca,
    ) {}

    public function command(): string
    {
        return 'tls install';
    }

    public function toPayload(): array
    {
        return ['certificate' => $this->certificate, 'key' => $this->key, 'ca' => $this->ca];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
