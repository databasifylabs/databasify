<?php

declare(strict_types=1);

namespace App\DTOs\Networks;

use App\Enums\Servers\NetworkMemberStatus;

final readonly class NetworkHealth
{
    private const int STALE_SECONDS = 180;

    public function __construct(
        public NetworkMemberStatus $status,
        public ?string $issue = null,
    ) {}

    /**
     * @param  array<string, string>  $expected
     */
    public static function of(WireguardStatus $state, array $expected): self
    {
        if (! $state->interfaceUp) {
            return new self(NetworkMemberStatus::Unreachable, 'the WireGuard interface is down');
        }

        $missing = array_diff_key($expected, $state->handshakes);
        $unknown = count(array_diff_key($state->handshakes, $expected));

        if ($missing !== [] || $unknown > 0) {
            return new self(NetworkMemberStatus::OutOfSync, self::syncIssueOf($missing, $unknown));
        }

        $stale = collect($expected)
            ->map(fn (string $name, string $key): ?string => self::stalenessOf($name, $state->secondsSinceHandshakeWith($key)))
            ->filter()
            ->values();

        return $stale->isEmpty() ? new self(NetworkMemberStatus::Connected) : new self(NetworkMemberStatus::Degraded, $stale->implode(', '));
    }

    /**
     * @param  array<string, string>  $missing
     */
    private static function syncIssueOf(array $missing, int $unknown): string
    {
        return collect([
            $missing === [] ? null : 'missing '.implode(', ', $missing),
            $unknown === 0 ? null : "{$unknown} unknown ".($unknown === 1 ? 'peer' : 'peers'),
        ])->filter()->implode(', ');
    }

    private static function stalenessOf(string $name, ?int $seconds): ?string
    {
        if ($seconds === null) {
            return "never connected to {$name}";
        }

        if ($seconds <= self::STALE_SECONDS) {
            return null;
        }

        return "no handshake with {$name} for ".intdiv($seconds, 60).' min';
    }
}
