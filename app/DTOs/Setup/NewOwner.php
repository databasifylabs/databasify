<?php

declare(strict_types=1);

namespace App\DTOs\Setup;

final readonly class NewOwner
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    /**
     * @return array{name: string, email: string, password: string}
     */
    public function toRow(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'password' => $this->password];
    }
}
