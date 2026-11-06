<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class ServerAccounts
{
    /**
     * @param  list<string>  $users
     * @param  list<string>  $databases
     */
    public function __construct(
        public array $users,
        public array $databases,
    ) {}

    /**
     * @param  list<string>  $reservedUsers
     * @param  list<string>  $reservedDatabases
     */
    public static function fromOutput(string $output, array $reservedUsers, array $reservedDatabases): self
    {
        $lines = explode("\n", $output);

        return new self(self::namesIn($lines, 'user:', $reservedUsers), self::namesIn($lines, 'database:', $reservedDatabases));
    }

    public function hasUser(string $name): bool
    {
        return in_array($name, $this->users, true);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $reserved
     * @return list<string>
     */
    private static function namesIn(array $lines, string $prefix, array $reserved): array
    {
        return array_values(collect($lines)
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => str_starts_with($line, $prefix))
            ->map(fn (string $line): string => substr($line, strlen($prefix)))
            ->reject(fn (string $name): bool => $name === '' || in_array($name, $reserved, true))
            ->unique()
            ->all());
    }
}
