<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\DTOs\Databases\DatabaseSettings;

final readonly class DefaultAccount
{
    public function __construct(
        public string $database,
        public string $user,
        public string $password,
    ) {}

    public static function of(DatabaseSettings $settings): ?self
    {
        $database = $settings->defaultSchema();
        $user = $settings->defaultUsername();
        $password = $settings->defaultPassword();

        if ($database === '' || $user === '' || $password === '') {
            return null;
        }

        return new self($database, $user, $password);
    }

    /**
     * @return array{database: string, user: string, password: string}
     */
    public function toPayload(): array
    {
        return ['database' => $this->database, 'user' => $this->user, 'password' => $this->password];
    }
}
