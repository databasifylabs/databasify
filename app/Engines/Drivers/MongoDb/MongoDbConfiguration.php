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

    public function __construct(
        private MongoDbDriver $driver,
        private NodeState $state,
    ) {}

    public function configure(NodeConfig $request): void
    {
        if ($request->secrets->admin === null || $request->secrets->replication === null) {
            throw new InvalidEngineRequest('invalid_request', 'MongoDB needs the admin and replication secrets');
        }

        if (count($request->members) > self::MAX_REPLICA_SET) {
            throw new InvalidEngineRequest('invalid_request', sprintf('a MongoDB replica set has at most %d members; got %d', self::MAX_REPLICA_SET, count($request->members)));
        }

        $catalog = MongoDbSettings::catalog();
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($this->driver, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $conf = MongoDbFiles::HOME.'/conf';

        $this->driver->run('mongodb/nodes/configure', env: [
            'REQUEST_JSON' => NodeState::requestJsonOf($request),
            'TUNED_JSON' => $saved === null ? NodeState::tunedJsonOf($tuned) : '',
            'FILES' => $this->filesTsvOf([
                new ConfigFile("{$conf}/mongod.conf", 0o600, MongoDbFiles::mongodConfOf($catalog->effective($tuned, $request->settings))),
                new ConfigFile("{$conf}/keyfile", 0o400, MongoDbFiles::keyfileOf($request->secrets->replication)),
                new ConfigFile("{$conf}/admin.pw", 0o600, $request->secrets->admin),
            ]),
            'MANIFEST' => base64_encode(MongoDbFiles::manifestOf($request)),
            'START_AGENT' => $request->archive !== null && $request->secrets->agent !== null ? 'true' : 'false',
        ]);
    }

    public function savedRequestOf(): NodeConfig
    {
        return $this->state->requestOf($this->driver, self::REQUEST_STATE) ?? throw new EngineBusy('busy', 'run node configure first');
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
     * @param  list<ConfigFile>  $files
     */
    private function filesTsvOf(array $files): string
    {
        return implode("\n", array_map(fn (ConfigFile $file): string => $file->path."\t".decoct($file->mode)."\t".base64_encode($file->content), $files));
    }
}
