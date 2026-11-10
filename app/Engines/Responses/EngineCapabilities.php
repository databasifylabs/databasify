<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Data\Ports;
use App\Engines\Payload;

final readonly class EngineCapabilities implements EngineReply
{
    /**
     * @param  list<string>  $commands
     * @param  list<string>  $passwordEncryptions
     * @param  list<string>  $reservedUsers
     * @param  list<string>  $reservedDatabases
     */
    public function __construct(
        public array $commands,
        public array $passwordEncryptions,
        public ?string $defaultPasswordEncryption,
        public array $reservedUsers,
        public array $reservedDatabases,
        public Ports $ports,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(
            commands: $payload->strings('commands'),
            passwordEncryptions: $payload->strings('passwordEncryptions'),
            defaultPasswordEncryption: $payload->nullableString('defaultPasswordEncryption'),
            reservedUsers: $payload->strings('reservedUsers'),
            reservedDatabases: $payload->strings('reservedDatabases'),
            ports: Ports::fromPayload($payload->object('ports')),
        );
    }

    public function offers(string $command): bool
    {
        return in_array($command, $this->commands, true);
    }

    /**
     * @return array{commands: list<string>, passwordEncryptions: list<string>, defaultPasswordEncryption: ?string, reservedUsers: list<string>, reservedDatabases: list<string>, ports: array{client: int, pool: ?int, peers: list<int>}}
     */
    public function toArray(): array
    {
        return [
            'commands' => $this->commands,
            'passwordEncryptions' => $this->passwordEncryptions,
            'defaultPasswordEncryption' => $this->defaultPasswordEncryption,
            'reservedUsers' => $this->reservedUsers,
            'reservedDatabases' => $this->reservedDatabases,
            'ports' => $this->ports->toArray(),
        ];
    }
}
