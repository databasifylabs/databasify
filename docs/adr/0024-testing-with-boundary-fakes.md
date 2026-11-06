# 0024. Tests fake the boundaries; real servers are tested by hand

- Status: Accepted
- Date: 2026-11-04

## Context

Most behaviour ends in an SSH script, a registry call or a provider API. CI cannot reach real servers, and mocks of those calls hide what the scripts actually do.

## Decision

- Outside systems sit behind contracts bound with `#[Bind]`: `ServerConnection`, `ImageRegistry`, `CertificateAuthority` and `PortProbe`. Every feature test replaces the first three with hand-written fakes from `tests/Support` in a Pest `beforeEach`.
- Provider and DNS APIs are faked with `Http::fake`.
- Scripts are tested as rendered text and syntax-checked (`bash -n`, `node --check`).
- CI runs `composer ci:check`: Pest with 100% type coverage, PHPStan at max, Pint and the frontend checks. It never touches real servers.
- Engine behaviour on real servers is checked by an end-to-end run on three provider servers (`.claude/agents/e2e-engine-tester.md`), outside CI.

## Consequences

- A new outside dependency needs a contract and a fake.
- A script can render correctly and still do the wrong thing on a server; only the end-to-end run catches that.

## Alternatives considered

- **Mocking SSH calls with Mockery:** not used, because hand-written fakes record what ran and stay readable.

## Evidence

- `tests/Pest.php`
- `tests/Support/FakeServerConnection.php`
- `tests/Feature/DatabaseProviders/ScriptSyntaxTest.php`
- `.claude/skills/adding-database-engine/rules/testing.md`
- `.claude/agents/e2e-engine-tester.md`
