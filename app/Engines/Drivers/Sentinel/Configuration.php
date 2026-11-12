<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\Member;
use App\Engines\Drivers\NodeState;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\NodeConfigure as NodeConfigureReply;
use App\Engines\Settings\SettingsCatalog;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Configuration
{
    public const string TUNED_STATE = 'state/tuned.json';

    public const string REQUEST_STATE = 'state/node.json';

    public function __construct(
        private SentinelScripts $scripts,
        private NodeState $state,
    ) {}

    public function configure(DatabaseNode $node, NodeConfigure $request): NodeConfigureReply
    {
        $engine = SentinelScripts::engineOf($node);

        if ($request->secrets->admin === null) {
            throw new InvalidEngineRequest('invalid_request', "{$engine->id} needs the admin secret");
        }

        $catalog = $this->catalogOf($node);
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($node, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $effective = NodeState::withSettings($request, $catalog->effective($tuned, $request->settings));
        $primary = $this->primaryFor($node, $request);

        $reply = $this->scripts->run($node, 'nodes/configure', [
            'PASSWORD' => $request->secrets->admin,
            'SERVER_CONF' => base64_encode(SentinelFiles::serverConfOf($engine, $effective, $primary)),
            'SENTINEL_CONF' => $request->clustered ? base64_encode(SentinelFiles::sentinelConfOf($engine, $request, $primary)) : '',
            'MANIFEST' => base64_encode(SentinelFiles::manifestOf($engine, $request)),
            'REQUEST_JSON' => NodeState::requestJsonOf($request),
            'TUNED_JSON' => $saved === null ? NodeState::tunedJsonOf($tuned) : '',
            'GUARD_EMPTY_PRIMARY' => $request->clustered && ! $request->readOnly && ($primary === '' || $primary === $request->ip) ? 'true' : 'false',
            'PERSISTENT' => SentinelFiles::persistent($request->settings) ? 'true' : 'false',
            'PEERS' => $this->peersOf($request),
        ], timeout: 300);

        return new NodeConfigureReply($reply->json()->bool('changed'));
    }

    public function catalogOf(DatabaseNode $node): SettingsCatalog
    {
        return SentinelSettings::catalogFor(SentinelScripts::engineOf($node), $node->database->version_line);
    }

    public function savedRequestOf(DatabaseNode $node): NodeConfigure
    {
        return $this->state->requestOf($node, self::REQUEST_STATE) ?? throw new EngineBusy('busy', 'run node configure first');
    }

    /**
     * @return array<string, string>
     */
    public function tunedOf(DatabaseNode $node, NodeConfigure $request): array
    {
        return $this->state->tunedOrNewOf($node, self::TUNED_STATE, $this->catalogOf($node), $request);
    }

    /**
     * @return array<string, string>
     */
    public function effectiveOf(DatabaseNode $node, NodeConfigure $request): array
    {
        return $this->catalogOf($node)->effective($this->tunedOf($node, $request), $request->settings);
    }

    public function peersOf(NodeConfigure $request): string
    {
        $peers = array_filter($request->members, fn (Member $member): bool => $member->ip !== $request->ip);

        return implode(' ', array_map(fn (Member $member): string => $member->ip, $peers));
    }

    private function primaryFor(DatabaseNode $node, NodeConfigure $request): string
    {
        if (! $request->clustered) {
            return '';
        }

        $reported = $this->scripts->run($node, 'nodes/primary', ['PEERS' => $this->peersOf($request)])->json()->string('primary');

        return $reported !== '' ? $reported : ($request->primary ?? '');
    }
}
