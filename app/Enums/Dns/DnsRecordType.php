<?php

declare(strict_types=1);

namespace App\Enums\Dns;

enum DnsRecordType: string
{
    case A = 'A';
    case Srv = 'SRV';
    case Txt = 'TXT';
}
