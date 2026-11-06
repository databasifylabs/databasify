<?php

declare(strict_types=1);

namespace App\DTOs\Dns;

use App\Enums\Dns\DnsRecordType;

final readonly class DnsRecord
{
    public const string MARKER = 'databasify';

    public function __construct(
        public DnsRecordType $type,
        public string $name,
        public string $content,
        public ?string $id = null,
        public bool $managed = true,
    ) {}
}
