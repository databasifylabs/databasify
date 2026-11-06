# 0027. One provider HTTP client with timeouts and safe retries

- Status: Superseded by 0030
- Date: 2026-11-05

## Context

Four server providers and one DNS provider each carried a private copy of the HTTP builder. None set a timeout beyond Laravel's 30 second default, none retried, and a dropped connection escaped as `ConnectionException`, so every caller caught two exception types. Providers only order the server (see 0019), so their calls sit inside queued jobs and request-bound screens where a hung call is expensive and a repeated create would order a second server.

## Decision

`ProviderHttp` builds every provider request: bearer token, JSON, a 5 second connect timeout and a 20 second response timeout, and any failed response becomes `ProviderRequestFailed` with the message each driver extracts from its own error body.

Only `GET` retries, at `200`, `1000` and `3000` ms, and only after a dropped connection, a 429 or a 5xx. `POST` and `DELETE` never retry, because a repeated create orders a second server. The retry rule lives in `TransientFailure`, which the image registry client shares for its `GET` requests.

A dropped connection always becomes `ProviderRequestFailed::unreachable()`. Callers catch only `ProviderRequestFailed`. The exception renders itself as a 422 JSON message, so a controller needs no try/catch.

Drivers call `ProviderHttp` statically, so the driver constructor stays `new Driver($provider)` and plugin drivers keep building themselves the same way.

## Consequences

- A transient provider failure while polling a new server no longer fails the launch.
- A worst-case failing `GET` takes about four timeouts plus 4.2 seconds, so a poll can wait longer before it reports the error.
- A new driver reuses the client instead of copying a builder.
- Drivers, plugin drivers included, must use `ProviderHttp` or throw `ProviderRequestFailed`; callers catch only that exception, so a raw `ConnectionException` is no longer caught and surfaces as a server error.
- A provider that supports idempotency keys could allow safe `POST` retries later; that would be a new decision.

## Alternatives considered

- Retry every verb: rejected, a retried create can order two servers.
- A `Http::macro` per provider: rejected, it would not cover the error mapping or the dropped-connection conversion.

## Evidence

- `app/Services/Providers/ProviderHttp.php`
- `app/Services/Http/TransientFailure.php`
- `app/Exceptions/Providers/ProviderRequestFailed.php`
- `app/ServerProviders/Drivers/Hetzner/Hetzner.php`
- `app/DnsProviders/Drivers/Cloudflare/Cloudflare.php`
- `tests/Unit/Services/ProviderHttpTest.php`
