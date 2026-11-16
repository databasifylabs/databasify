<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Settings\SettingsCatalog;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;
use Illuminate\Support\Str;

final readonly class SentinelConfiguration
{
    public const string TUNED_STATE = 'state/tuned.json';

    public const string REQUEST_STATE = 'state/node.json';

    public function __construct(
        private SentinelDriver $driver,
        private NodeState $state,
    ) {}

    public function configure(NodeConfig $request): void
    {
        $engine = $this->driver::flavor();

        if ($request->secrets->admin === null) {
            throw new InvalidEngineRequest("{$engine->id} needs the admin secret");
        }

        $catalog = $this->catalog();
        $catalog->check($request->settings);
        $saved = $this->state->tunedOf($this->driver, self::TUNED_STATE);
        $tuned = $saved ?? $catalog->tuned(NodeState::selfOf($request));
        $effective = NodeState::withSettings($request, $catalog->effective($tuned, $request->settings));
        $primary = $this->primaryFor($request);

        $this->driver->run('engines.sentinel.nodes.configure', [
            'password' => $request->secrets->admin,
            'serverConf' => SentinelFiles::serverConfOf($engine, $effective, $primary),
            'sentinelConf' => $request->clustered ? SentinelFiles::sentinelConfOf($engine, $request, $primary) : null,
            'manifest' => SentinelFiles::manifestOf($engine, $request),
            'requestJson' => NodeState::requestJsonOf($request),
            'tunedJson' => $saved === null ? NodeState::tunedJsonOf($tuned) : null,
            'guardsEmptyPrimary' => $request->clustered && ! $request->readOnly && ($primary === '' || $primary === $request->ip),
            'persistent' => SentinelFiles::persistent($request->settings),
            'peers' => $this->peersOf($request),
        ], timeout: 300);
    }

    public function catalog(): SettingsCatalog
    {
        return SentinelSettings::catalogFor($this->driver::flavor(), $this->driver->node->database->version_line);
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

    /**
     * @return list<string>
     */
    private function peersOf(NodeConfig $request): array
    {
        return array_values(collect($request->members)
            ->reject(fn (Member $member): bool => $member->ip === $request->ip)
            ->map(fn (Member $member): string => $member->ip)
            ->all());
    }

    private function primaryFor(NodeConfig $request): string
    {
        if (! $request->clustered) {
            return '';
        }

        $reply = $this->driver->read('engines.sentinel.nodes.primary', ['peers' => $this->peersOf($request)], ['CLI_SECRET' => (string) $request->secrets->admin]);
        $reported = collect($reply->strings('answers'))
            ->map(fn (string $answer): string => Str::before($answer, "\n"))
            ->first(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false);

        return $reported ?? ($reply->string('configured') ?: ($request->primary ?? ''));
    }
}
