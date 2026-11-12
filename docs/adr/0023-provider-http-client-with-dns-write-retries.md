# 0023. One provider HTTP client; DNS record writes retry too

- Status: Accepted
- Date: 2026-11-05

## Context

The first provider HTTP client gave every provider one client and retried only `GET`, because a repeated server create orders a second server. DNS record writes do not carry that cost. In a live run a single dropped connection while Cloudflare deleted the record of a removed node failed the whole DNS sync, the every-minute status refresh swallowed the error, and the replica name kept pointing at the removed node with nothing on screen to say why.

## Decision

`ProviderHttp` builds every provider request: bearer token, JSON, a 5 second connect timeout and a 20 second response timeout, and any failed response becomes `ProviderRequestFailed` with the message each driver extracts from its own error body.

`GET` retries, at `200`, `1000` and `3000` ms, and only after a dropped connection, a 429 or a 5xx. Server `POST` and `DELETE` calls never retry, because a repeated create orders a second server. DNS record creates and deletes retry with the same rule: deleting a record is idempotent (a missing record counts as deleted), and a repeated create at worst meets Cloudflare's identical-record error, which the next sync clears because it reads the records again. The retry rule lives in `TransientFailure`, which the image registry client shares for its `GET` requests.

A dropped connection always becomes `ProviderRequestFailed::unreachable()`. Callers catch only `ProviderRequestFailed`. The exception renders itself as a 422 JSON message, so a controller needs no try/catch.

When a database DNS sync still fails after the retries, `SyncDatabaseDns` records a failed `Sync DNS records` server log on the database, like the record clean-up on node removal and database deletion does. It logs once per outage: a `dns-sync-failed:<database id>` cache key, kept for 24 hours, holds back further logs until a sync succeeds and clears it. The records stay unchanged in the cache, so the next refresh tries again.

Drivers call `ProviderHttp` statically, so the driver constructor stays `new Driver($provider)` and plugin drivers keep building themselves the same way.

## Consequences

- A transient provider failure while polling a new server no longer fails the launch.
- A transient Cloudflare failure no longer leaves a name on a removed or demoted node.
- A worst-case failing request that retries takes about four timeouts plus 4.2 seconds, so a poll or a DNS sync can wait longer before it reports the error. `DnsRecordPublisher` therefore holds its per-name lock for 120 seconds, past a worst-case retrying sync (about 84 seconds), so a second sync of the same name never starts while the first still writes.
- During a Cloudflare outage each database gets one failed log, and one more each day the outage lasts, so real logs stay on its activity list.
- A new driver reuses the client instead of copying a builder.
- Drivers, plugin drivers included, must use `ProviderHttp` or throw `ProviderRequestFailed`; callers catch only that exception, so a raw `ConnectionException` is no longer caught and surfaces as a server error.
- A server provider that supports idempotency keys could allow safe `POST` retries later; that would be a new decision.

## Alternatives considered

- Retry every verb: rejected, a retried server create can order two servers.
- A `Http::macro` per provider: rejected, it would not cover the error mapping or the dropped-connection conversion.
- Retry the whole DNS sync instead of each request: rejected, it would add a second retry mechanism beside `TransientFailure`.
- Email an alert for a failed DNS sync: rejected, the refresh runs every minute and would email every minute during an outage.

## Evidence

- `app/Services/Providers/ProviderHttp.php`
- `app/Services/Http/TransientFailure.php`
- `app/Exceptions/Providers/ProviderRequestFailed.php`
- `app/ServerProviders/Drivers/Hetzner/Hetzner.php`
- `app/DnsProviders/Drivers/Cloudflare/Cloudflare.php`
- `app/Actions/Databases/Domains/SyncDatabaseDns.php`
- `app/Services/Dns/DnsRecordPublisher.php`
- `tests/Feature/Databases/Domains/SyncDatabaseDnsTest.php`
