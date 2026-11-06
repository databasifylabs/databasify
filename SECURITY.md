# Security Policy

## Supported versions

| Version | Supported                                      |
| ------- | ---------------------------------------------- |
| 0.x     | Yes, security fixes go into the latest release |

## Reporting a vulnerability

Please **do not** report security vulnerabilities in public issues, discussions or pull requests.

Report them privately through [GitHub's vulnerability reporting](https://github.com/databasifylabs/databasify/security/advisories/new) and include:

- a description of the vulnerability and its impact
- steps to reproduce it, with the version and the engine or OS involved
- any proof-of-concept code, logs or screenshots that help

We will acknowledge your report as soon as we can, keep you updated while we work on a fix, and credit you in the advisory unless you prefer otherwise. Please give us reasonable time to release a fix before you disclose anything publicly.

## Scope

Databasify has one trust level: every signed-in user can manage all servers and databases of the instance, including running scripts on those servers over SSH. Reports are judged by the boundary they cross.

**In scope**

- Reading or changing data, or running code, without signing in.
- Bypassing authentication, two-factor authentication or passkeys.
- Running code on the Databasify host, or on a managed server, through input that should be treated as data (for example a database, user or schema name).
- Leaking credentials (SSH keys, provider tokens, database passwords) to someone who is not signed in, or to logs and network traffic.
- Weaknesses in how Databasify sets up managed databases, such as TLS, firewall rules, default accounts or file permissions on the servers.

**Out of scope**

- Actions a signed-in user can already take through normal features.
- Problems that require root access to the Databasify host or to a managed server.
- Vulnerabilities in the upstream database engines, unless Databasify's configuration causes them.
- Denial-of-service, spam, social engineering and findings from automated scanners without a working impact.

## Guidelines for researchers

- Test only on your own installation and servers.
- Do not access, change or delete data that is not yours.
- Stop and report as soon as you have shown the issue.

Thank you for helping keep Databasify and its users safe.
