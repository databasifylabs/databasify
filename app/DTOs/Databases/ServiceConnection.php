<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class ServiceConnection
{
    public function __construct(
        public string $uriScheme,
        public string $uriQuery,
        public string $command,
        public string $envNote,
        public string $standbyUriQuery,
        public string $standbyCommand,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $text = fn (string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';

        return new self($text('uri_scheme'), $text('uri_query'), $text('command'), $text('env_note'), $text('standby_uri_query'), $text('standby_command'));
    }

    /**
     * @return array{uri_scheme: string, uri_query: string, uri_port: false, command: string, env_note: string, standby_uri_query: string, standby_command: string}
     */
    public function toArray(): array
    {
        return [
            'uri_scheme' => $this->uriScheme,
            'uri_query' => $this->uriQuery,
            'uri_port' => false,
            'command' => $this->command,
            'env_note' => $this->envNote,
            'standby_uri_query' => $this->standbyUriQuery,
            'standby_command' => $this->standbyCommand,
        ];
    }
}
