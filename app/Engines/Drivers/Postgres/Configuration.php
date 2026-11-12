<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\Member;
use App\Engines\Drivers\NodeState;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\NodeConfigure as NodeConfigureReply;
use App\Engines\Settings\SettingsCatalog;
use App\Models\DatabaseNode;

final readonly class Configuration
{
    public const string TUNED_STATE = 'conf/tuned.json';

    private const string REQUEST_STATE = 'conf/request.json';

    private const string POOL_SIZE_PLACEHOLDER = '__DEFAULT_POOL_SIZE__';

    private const int CONFIGURE_TIMEOUT = 300;

    public function __construct(
        private EngineScripts $scripts,
        private NodeState $state,
        private Accounts $accounts,
    ) {}

    public function configure(DatabaseNode $node, NodeConfigure $request): NodeConfigureReply
    {
        $line = $node->database->version_line;
        $catalog = $this->catalogOf($node);
        $catalog->check($request->settings);
        $saved = $this->savedTunedOf($node);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $files = PostgresFiles::of(NodeState::withSettings($request, $catalog->effective($tuned, $request->settings)), $line, self::POOL_SIZE_PLACEHOLDER);

        $reply = $this->scripts->run($node, 'postgres/nodes/configure', $this->accounts->dataFor($node), timeout: self::CONFIGURE_TIMEOUT, env: [
            'REQUEST_JSON' => NodeState::requestJsonOf($request),
            'TUNED_JSON' => $saved === null ? NodeState::tunedJsonOf($tuned) : '',
            'FILES' => NodeState::filesJsonOf($files->files),
            'MANIFEST' => base64_encode($files->manifest),
            'CLUSTERED' => $request->clustered ? 'true' : 'false',
            'READ_ONLY' => $request->readOnly ? 'true' : 'false',
            'ARCHIVE' => $request->archive === null ? 'false' : 'true',
            'LINE' => $line,
            'IP' => $request->ip,
            'MEMBERS' => json_encode(array_map(fn (Member $member): array => $member->toPayload(), $request->members), JSON_THROW_ON_ERROR),
        ]);

        return new NodeConfigureReply($reply->json()->bool('changed'));
    }

    /**
     * @return array<string, string>|null
     */
    public function savedTunedOf(DatabaseNode $node): ?array
    {
        return $this->state->tunedOf($node, self::TUNED_STATE);
    }

    public function catalogOf(DatabaseNode $node): SettingsCatalog
    {
        return PostgresSettings::catalogFor($node->database->version_line);
    }

    public function savedRequestOf(DatabaseNode $node): ?NodeConfigure
    {
        return $this->state->requestOf($node, self::REQUEST_STATE);
    }
}
