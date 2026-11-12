<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\MigrationSource;
use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationStart;
use App\Engines\Responses\Done;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;
use App\Engines\Responses\MigrationProgress;
use App\Engines\Scripts\ScriptView;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Migration
{
    public function __construct(
        private EngineScripts $scripts,
        private Accounts $accounts,
        private ScriptView $views,
    ) {}

    public function check(DatabaseNode $node, MigrationCheck $request): MigrationCheckReply
    {
        $env = [...$this->sourceEnv($request->source), 'DATABASES' => json_encode($request->databases, JSON_THROW_ON_ERROR)];

        return MigrationCheckReply::fromPayload($this->scripts->run($node, 'postgres/migration/check', $this->accounts->dataFor($node), timeout: 600, env: $env)->json());
    }

    public function start(DatabaseNode $node, MigrationStart $request): Done
    {
        if ($request->owner === null) {
            throw new InvalidEngineRequest('invalid_request', 'PostgreSQL migrations need an owner');
        }

        if ($request->continuous) {
            throw new InvalidEngineRequest('invalid_request', 'continuous PostgreSQL migrations need a publication');
        }

        $this->scripts->run($node, 'postgres/migration/start', $this->accounts->dataFor($node), env: [
            ...$this->sourceEnv($request->source),
            'MIGRATION_DATABASES' => implode(' ', $request->databases),
            'MIGRATION_OWNER' => $request->owner,
            'MIGRATION_CONTINUOUS' => 'false',
            'MIGRATION_PUBLICATION' => '',
            'COPY_SCRIPT' => base64_encode($this->views->render($node, 'postgres/migration/copy', $this->accounts->dataFor($node))),
        ]);

        return new Done;
    }

    public function progress(DatabaseNode $node): MigrationProgress
    {
        return MigrationProgress::fromPayload($this->scripts->run($node, 'postgres/migration/progress', $this->accounts->dataFor($node))->json());
    }

    public function stop(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'postgres/migration/stop', $this->accounts->dataFor($node), timeout: 600);

        return new Done;
    }

    /**
     * @return array{SOURCE_HOST: string, SOURCE_PORT: string, SOURCE_USER: string, SOURCE_PASSWORD: string, SOURCE_SSLMODE: string}
     */
    private function sourceEnv(MigrationSource $source): array
    {
        return [
            'SOURCE_HOST' => $source->host,
            'SOURCE_PORT' => (string) $source->port,
            'SOURCE_USER' => $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_SSLMODE' => $source->tls ? 'require' : 'prefer',
        ];
    }
}
