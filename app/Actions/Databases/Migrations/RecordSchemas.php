<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Models\Database;
use App\Support\Databases\SchemaName;
use App\Support\Migrations\NameList;

final readonly class RecordSchemas
{
    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    public function handle(Database $database, array $names): array
    {
        $candidates = array_values(array_diff(array_unique($names), $database->engine_capabilities->reservedDatabases ?? []));

        $this->logSkipped($database, array_values(array_filter($candidates, fn (string $name): bool => ! SchemaName::isValid($name))));

        $manageable = array_values(array_filter($candidates, SchemaName::isValid(...)));
        $this->record($manageable, $database);

        return $manageable;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function logSkipped(Database $database, array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $server = $database->primaryServer();
        $names = NameList::of($skipped);

        $server->logFailure('Record migrated databases', "Skipped {$names}: Databasify only records databases whose names start with a letter and contain only letters, digits and underscores. Manage them on {$server->name} yourself.", $database);
    }

    /**
     * @param  list<string>  $names
     */
    private function record(array $names, Database $database): void
    {
        foreach ($names as $name) {
            $database->schemas()->createOrFirst(['name' => $name]);
        }
    }
}
