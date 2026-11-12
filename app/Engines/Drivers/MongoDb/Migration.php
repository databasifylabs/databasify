<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationStart;
use App\Engines\Responses\Done;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;
use App\Engines\Responses\MigrationProgress;
use App\Models\DatabaseNode;

final readonly class Migration
{
    public const string UNIT = 'mongodb-migration';

    public function __construct(private EngineScripts $scripts) {}

    public function check(DatabaseNode $node, MigrationCheck $request): MigrationCheckReply
    {
        $facts = $this->scripts->run($node, 'mongodb/migration/check', timeout: 600, env: [
            'SOURCE_HOST' => "{$request->source->host}:{$request->source->port}",
            'SOURCE_USER' => $request->source->username,
            'SOURCE_PASSWORD' => $request->source->password,
            'SOURCE_TLS' => $request->source->tls ? 'true' : 'false',
            'DATABASES' => json_encode($request->databases, JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode(Accounts::RESERVED_DATABASES, JSON_THROW_ON_ERROR),
        ])->json();
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return new MigrationCheckReply(false, $error, null, null, false, null, [], [], [], [], [], []);
        }

        $version = $facts->string('version');
        $line = $node->database->version_line;
        $standalone = $facts->string('setName') === '';
        $sameMajor = implode('.', array_slice(explode('.', $version), 0, 2)) === $line;
        $warnings = [];

        if ($standalone) {
            $warnings[] = 'the source is a standalone server without an oplog; only a one-shot copy is possible';
        }

        if (! $sameMajor) {
            $warnings[] = "continuous sync needs the same major version ({$line}); only a one-shot copy is possible";
        }

        return new MigrationCheckReply(true, null, $version, null, ! $standalone && $sameMajor, intdiv($facts->int('size'), 1 << 20), $facts->strings('databases'), $facts->strings('unreadable'), [], $facts->strings('occupied'), $warnings, []);
    }

    public function start(DatabaseNode $node, MigrationStart $request): Done
    {
        $this->scripts->run($node, 'mongodb/migration/start', env: [
            'MIGRATION_HOST' => $request->source->host,
            'MIGRATION_PORT' => (string) $request->source->port,
            'MIGRATION_USER' => $request->source->username,
            'MIGRATION_PASSWORD' => $request->source->password,
            'MIGRATION_TLS' => $request->source->tls ? 'true' : 'false',
            'MIGRATION_DATABASES' => implode(' ', $request->databases),
            'MIGRATION_CONTINUOUS' => $request->continuous ? 'true' : 'false',
        ]);

        return new Done;
    }

    public function progress(DatabaseNode $node): MigrationProgress
    {
        return MigrationProgress::fromPayload($this->scripts->run($node, 'mongodb/migration/progress')->json());
    }

    public function stop(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'mongodb/migration/stop', timeout: 3900);

        return new Done;
    }
}
