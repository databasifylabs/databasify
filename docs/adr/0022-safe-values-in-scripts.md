# 0022. Values reach rendered scripts only in safe forms

- Status: Accepted
- Date: 2026-11-04

## Context

Scripts are Blade templates that run as root on users' servers ([0001](0001-ssh-control-plane.md)). Blade's `{{ }}` escapes for HTML, not for the shell, so any user value placed in a script as-is is a shell injection.

## Decision

Every value that reaches a script is in one of these forms:

- validated at the request to a strict character set (for example usernames and SSH keys), or, for names read from elsewhere such as the databases of a migration source, filtered to that character set before use (`SchemaName`),
- base64-encoded and decoded on the host (file contents through `put_file`, SQL through the helpers),
- quoted for its target format through `Support\Scripts\Literal` (SQL, TOML, INI, shell),
- a secret kept inside the uploaded script or an engine file, never on a command line ([0013](0013-secrets-and-ssh-keys.md) lists the one remaining exception).

Templates never reference PHP classes or use `@php`; the driver (its `*Variables()` methods) and the action running the step precompute every value and pass it to `Ssh::exec()` as a plain array.

## Consequences

- A new free-text field must pick one of these forms before it reaches a script.
- Script tests check the rendered text, so a raw value shows up in review.

## Alternatives considered

- **Escaping in the template with a shell-quoting helper:** not used, because it is easy to forget in one place and impossible to check.

## Evidence

- `app/Support/Scripts/Literal.php`
- `resources/views/ssh/databases/files/put.blade.php`
- `resources/views/ssh/databases/helpers.blade.php`
- `app/Http/Requests/Servers/StoreServerUserRequest.php`
- `app/Support/Databases/SchemaName.php`
