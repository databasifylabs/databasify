<?php

declare(strict_types=1);

namespace App\DTOs\Migrations;

use App\Support\Databases\SchemaName;

final readonly class SourceForm
{
    /**
     * @param  list<string>  $reservedDatabases
     */
    public function __construct(
        public int $defaultPort,
        public bool $credentialsRequired,
        public bool $selectsDatabases,
        public array $reservedDatabases = [],
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $credentials = $this->credentialsRequired ? 'required' : 'nullable';

        return [
            'username' => [$credentials, 'string', 'max:64', 'regex:/^[A-Za-z0-9_.@-]+$/'],
            'password' => [$credentials, 'string', 'max:255'],
            'databases' => [$this->selectsDatabases ? 'nullable' : 'prohibited', 'array'],
            'databases.*' => ['string', 'max:64', 'regex:'.SchemaName::PATTERN, 'not_in:'.implode(',', $this->reservedDatabases)],
        ];
    }

    /**
     * @return array{default_port: int, credentials_required: bool, selects_databases: bool}
     */
    public function toArray(): array
    {
        return [
            'default_port' => $this->defaultPort,
            'credentials_required' => $this->credentialsRequired,
            'selects_databases' => $this->selectsDatabases,
        ];
    }
}
