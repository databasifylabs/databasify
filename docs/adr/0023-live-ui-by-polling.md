# 0023. Live UI by polling, Reverb only for streams

- Status: Accepted
- Date: 2026-11-04

## Context

Pages show state that changes on remote servers: node status, running operations, migrations. Broadcasting every model change would need events for every state transition and would easily miss one.

## Decision

- Pages refresh their data with Inertia `usePoll` partial reloads every few seconds (for example 5 seconds on the databases list and 10 on the dashboard).
- Reverb carries only byte streams: server log output and terminal output. There are no model-change broadcasts. nginx proxies Reverb on the same origin.
- All endpoints are Inertia routes reached through Wayfinder; there is no public API.

## Consequences

- New live status in the UI means a new polled prop, not a new broadcast event.
- Automation has no stable API to call.
- Polling costs one partial request per open page every few seconds.

## Alternatives considered

- **Broadcasting model changes:** not used, for the reasons above.
- **A public REST or MCP API:** not part of 0.x.

## Evidence

- `resources/js/pages/dashboard.tsx`
- `resources/js/pages/databases/index.tsx`
- `app/Events/ServerLogOutputReceived.php`
- `app/Events/TerminalOutputReceived.php`
- `docker/nginx.conf`
