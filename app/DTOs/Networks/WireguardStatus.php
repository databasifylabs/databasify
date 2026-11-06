<?php

declare(strict_types=1);

namespace App\DTOs\Networks;

final readonly class WireguardStatus
{
    /**
     * @param  array<string, int>  $handshakes
     */
    public function __construct(
        public bool $interfaceUp,
        public int $now,
        public array $handshakes,
    ) {}

    public static function fromOutput(string $output): self
    {
        $lines = collect(explode("\n", trim($output)));
        $handshakes = $lines
            ->filter(fn (string $line): bool => str_starts_with($line, 'peer='))
            ->mapWithKeys(function (string $line): array {
                [$key, $handshake] = array_pad(explode(' ', substr($line, 5), 2), 2, '0');

                return [$key => (int) $handshake];
            })
            ->all();

        return new self(
            interfaceUp: $lines->contains('interface=up'),
            now: (int) substr($lines->first(fn (string $line): bool => str_starts_with($line, 'now=')) ?? 'now=0', 4),
            handshakes: $handshakes,
        );
    }

    public function secondsSinceHandshakeWith(string $publicKey): ?int
    {
        $handshake = $this->handshakes[$publicKey] ?? 0;

        return $handshake === 0 ? null : max(0, $this->now - $handshake);
    }
}
