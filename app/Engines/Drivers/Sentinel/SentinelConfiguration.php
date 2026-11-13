<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Settings\SettingsCatalog;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class SentinelConfiguration
{
    public const string TUNED_STATE = 'state/tuned.json';

    public const string REQUEST_STATE = 'state/node.json';

    public function __construct(
        private SentinelDriver $driver,
        private SentinelScripts $scripts,
        private NodeState $state,
    ) {}

    public function configure(NodeConfig $request): void
    {
        $engine = $this->driver::flavor();

        if ($request->secrets->admin === null) {
            throw new InvalidEngineRequest('invalid_request', "{$engine->id} needs the admin secret");
        }

        $catalog = $this->catalog();
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($this->driver, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $effective = NodeState::withSettings($request, $catalog->effective($tuned, $request->settings));
        $primary = $this->primaryFor($request);

        $this->scripts->run($this->driver, 'nodes/configure', [
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
    }

    public function catalog(): SettingsCatalog
    {
        return SentinelSettings::catalogFor($this->driver::flavor(), $this->driver->node->database->version_line);
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
        return $this->state->tunedOrNewOf($this->driver, self::TUNED_STATE, $this->catalog(), $request);
    }

    /**
     * @return array<string, string>
     */
    public function effectiveOf(NodeConfig $request): array
    {
        return $this->catalog()->effective($this->tunedOf($request), $request->settings);
    }

    /**
     * @return list<string>
     */
    public function restartOnlyKeys(): array
    {
        return SentinelSettings::restartOnlyKeys($this->driver::flavor(), $this->driver->node->database->version_line);
    }

    private function peersOf(NodeConfig $request): string
    {
        $peers = array_filter($request->members, fn (Member $member): bool => $member->ip !== $request->ip);

        return implode(' ', array_map(fn (Member $member): string => $member->ip, $peers));
    }

    private function primaryFor(NodeConfig $request): string
    {
        if (! $request->clustered) {
            return '';
        }

        $reported = $this->scripts->read($this->driver, 'nodes/primary', ['PEERS' => $this->peersOf($request), 'CLI_SECRET' => (string) $request->secrets->admin])->string('primary');

        return $reported !== '' ? $reported : ($request->primary ?? '');
    }
}
