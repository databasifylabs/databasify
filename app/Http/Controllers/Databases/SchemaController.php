<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Accounts\CreateSchema;
use App\Actions\Databases\Accounts\DeleteSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreSchemaRequest;
use App\Models\Database;
use App\Models\Schema;
use Illuminate\Http\RedirectResponse;

class SchemaController extends Controller
{
    public function store(StoreSchemaRequest $request, Database $database, CreateSchema $createSchema): RedirectResponse
    {
        $createSchema->handle($database, $request->schemaName());

        return back();
    }

    public function destroy(Database $database, Schema $schema, DeleteSchema $deleteSchema): RedirectResponse
    {
        $deleteSchema->handle($schema);

        return back();
    }
}
