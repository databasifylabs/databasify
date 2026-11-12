<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationStart;
use App\Engines\Responses\Done;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;
use App\Engines\Responses\MigrationProgress;
use App\Models\DatabaseNode;

final readonly class Migration
{
    public function __construct(
        private SentinelScripts $scripts,
        private Configuration $configuration,
    ) {}

    public function check(DatabaseNode $node, MigrationCheck $request): MigrationCheckReply
    {
        $source = $request->source;
        $facts = $this->scripts->run($node, 'migration/check', [
            'SOURCE_HOST' => $source->host,
            'SOURCE_PORT' => (string) $source->port,
            'SOURCE_USER' => $source->username === 'default' ? '' : $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_TLS' => $source->tls ? 'true' : '',
        ], timeout: 300)->json();
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return new MigrationCheckReply(false, $error, null, null, false, null, [], [], [], [], [], []);
        }

        return new MigrationCheckReply(true, null, $facts->nullableString('version'), null, true, $facts->nullableInt('sizeMb'), [], [], [], [], $facts->strings('warnings'), []);
    }

    public function start(DatabaseNode $node, MigrationStart $request): Done
    {
        $engine = SentinelScripts::engineOf($node);
        $password = (string) $this->configuration->savedRequestOf($node)->secrets->admin;
        $this->scripts->run($node, 'migration/start', ['SHAKE' => base64_encode(SentinelFiles::shakeConfigOf($request, $engine->home().'/migration', $password))]);

        return new Done;
    }

    public function progress(DatabaseNode $node): MigrationProgress
    {
        return MigrationProgress::fromPayload($this->scripts->run($node, 'migration/progress')->json());
    }

    public function stop(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'migration/stop');

        return new Done;
    }
}
