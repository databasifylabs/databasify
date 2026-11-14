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
        $candidates = array_values(array_diff(array_unique($names), $database->facts()->reservedDatabases));
        $skipped = array_values(array_filter($candidates, fn (string $name): bool => ! SchemaName::isValid($name)));
        $manageable = array_values(array_filter($candidates, SchemaName::isValid(...)));

        if ($skipped !== []) {
            $server = $database->primaryServer();
            $list = NameList::of($skipped);
            $server->logFailure('Record migrated databases', "Skipped {$list}: Databasify only records databases whose names start with a letter and contain only letters, digits and underscores. Manage them on {$server->name} yourself.", $database);
        }

        foreach ($manageable as $name) {
            $database->schemas()->createOrFirst(['name' => $name]);
        }

        return $manageable;
    }
}
