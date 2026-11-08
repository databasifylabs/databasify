# 0017. Browser terminal as a queued SSH shell

- Status: Accepted
- Date: 2026-11-04

## Context

Users need a shell on their servers without leaving the app or setting up their own SSH client. The app already holds SSH access to every server ([0001](0001-ssh-control-plane.md)), but a web request cannot hold an interactive session open.

## Decision

- Opening a terminal on a ready server starts `RunTerminalSessionJob` on the `terminals` queue. The job holds an interactive phpseclib shell and runs `exec sudo -i` when the SSH user is not root.
- Keystrokes arrive over HTTP POST (base64, at most 128 KB per request) and are handed to the job through a lock-guarded cache key.
- Output is broadcast over Reverb on `terminals.{id}`, a channel bound to the user who opened the session.
- A session closes after 15 minutes idle (`TerminalSession::IDLE_SECONDS`) or 2 hours in total (`TerminalSession::MAX_SECONDS`).

## Consequences

- Every open terminal holds one Horizon worker for up to 2 hours. The `terminals` supervisor is small (2 workers by default), so concurrent terminals are limited.
- Keystrokes pass through the default cache store ([0021](0021-app-state-sqlite-and-redis.md)).
- Any signed-in user gets a root shell on every server, which is acceptable only because there is a single owner ([0016](0016-app-architecture.md)).

## Alternatives considered

- **Reverb client events for keystrokes:** not used; input goes over authenticated HTTP requests only.

## Evidence

- `app/Services/Ssh/TerminalSession.php`
- `app/Jobs/Servers/RunTerminalSessionJob.php`
- `app/Actions/Servers/RunTerminalSession.php`
- `app/Services/Ssh/PhpseclibConnection.php`
- `app/Http/Requests/Servers/TerminalInputRequest.php`
- `routes/channels.php`
- `config/horizon.php`
