<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Data\ConfigFile;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\Support\NodeState;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MongoDbConfiguration
{
    public const string TUNED_STATE = 'state/tuned.json';

    public const string REQUEST_STATE = 'state/node.json';

    private const int MAX_REPLICA_SET = 50;

    private const int JOB_MODE = 0o700;

    public function __construct(
        private MongoDbDriver $driver,
        private NodeState $state,
    ) {}

    public function configure(NodeConfig $request): void
    {
        if ($request->secrets->admin === null || $request->secrets->replication === null) {
            throw new InvalidEngineRequest('MongoDB needs the admin and replication secrets');
        }

        if (count($request->members) > self::MAX_REPLICA_SET) {
            throw new InvalidEngineRequest(sprintf('a MongoDB replica set has at most %d members; got %d', self::MAX_REPLICA_SET, count($request->members)));
        }

        $catalog = MongoDbSettings::catalog();
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($this->driver, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $conf = MongoDbFiles::HOME.'/conf';
        $mongodConf = "{$conf}/mongod.conf";
        $keyfile = "{$conf}/keyfile";

        $this->driver->run('engines.mongodb.nodes.configure', [
            'requestJson' => NodeState::requestJsonOf($request),
            'tunedJson' => $saved === null ? NodeState::tunedJsonOf($tuned) : null,
            'files' => [
                new ConfigFile($mongodConf, 0o600, MongoDbFiles::mongodConfOf($catalog->effective($tuned, $request->settings))),
                new ConfigFile($keyfile, 0o400, MongoDbFiles::keyfileOf($request->secrets->replication)),
                new ConfigFile("{$conf}/admin.pw", 0o600, $request->secrets->admin),
                ...$this->jobsFor($request),
            ],
            'owned' => [$mongodConf, $keyfile],
            'manifest' => MongoDbFiles::manifestOf($request),
            'agent' => MongoDbFiles::runsAgent($request),
        ]);
    }

    public function savedRequestOf(): NodeConfig
    {
        return $this->state->requestOf($this->driver, self::REQUEST_STATE) ?? throw new EngineBusy('run node configure first');
    }

    /**
     * @return array<string, string>
     */
    public function tunedOf(NodeConfig $request): array
    {
        return $this->state->tunedOrNewOf($this->driver, self::TUNED_STATE, MongoDbSettings::catalog(), $request);
    }

    /**
     * @return array<string, string>
     */
    public function effectiveOf(NodeConfig $request): array
    {
        return MongoDbSettings::catalog()->effective($this->tunedOf($request), $request->settings);
    }

    /**
     * @return list<ConfigFile>
     */
    private function jobsFor(NodeConfig $request): array
    {
        if (! MongoDbFiles::runsAgent($request)) {
            return [];
        }

        return [new ConfigFile(MongoDbFiles::BACKUP_JOB, self::JOB_MODE, $this->driver->script('engines.mongodb.jobs.backup'))];
    }
}
