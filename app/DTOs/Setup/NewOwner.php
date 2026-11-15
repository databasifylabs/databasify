<?php

declare(strict_types=1);

namespace App\DTOs\Setup;

use Illuminate\Support\ValidatedInput;

final readonly class NewOwner
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    public static function fromInput(ValidatedInput $input): self
    {
        return new self(
            name: $input->string('name')->trim()->value(),
            email: $input->string('email')->trim()->value(),
            password: $input->string('password')->value(),
        );
    }

    /**
     * @return array{name: string, email: string, password: string}
     */
    public function toRow(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'password' => $this->password];
    }
}
