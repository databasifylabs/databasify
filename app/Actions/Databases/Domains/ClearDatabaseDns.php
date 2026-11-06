<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\Models\Database;

final readonly class ClearDatabaseDns
{
    public function __construct(
        private ClearHostnames $clear,
        private ClearNodeDns $clearNode,
        private WithdrawServiceRecords $services,
    ) {}

    public function handle(Database $database): void
    {
        $zone = $database->dnsZone;
        $hostnames = $database->hostnames();

        if ($zone === null || $hostnames === null) {
            return;
        }

        $database->nodes()->readOnly()->get()->each($this->clearNode->handle(...));
        $this->withdrawServiceOf($database);
        $this->clear->handle($zone, $hostnames->all());
    }

    private function withdrawServiceOf(Database $database): void
    {
        $records = $database->serviceRecords();

        if ($records !== null) {
            $this->services->handle($records, $database->nodes()->get());
        }
    }
}
