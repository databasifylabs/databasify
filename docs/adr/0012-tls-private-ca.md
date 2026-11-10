# 0012. Private CA per database; public wildcard for key-value engines

- Status: Accepted
- Date: 2026-11-04

## Context

Every engine should serve TLS. Public certificates need a domain and a DNS provider, which not every user has. Some clients (notably Redis and Valkey clients) handle custom CAs poorly.

## Decision

- Each database gets its own private CA (EC P-256, valid 10 years) and a certificate per node (EC P-256, valid 825 days, `serverAuth` and `clientAuth`), issued by the app with PHP's openssl extension (`OwnCertificateAuthority`, `IssueNodeCertificates`). A node's certificate names the database hostnames, its SRV member host and its own addresses and hostnames. Each node gets its certificate, key and the CA with `databasify tls install`, which writes the files the engine needs and reloads it.
- CA and certificates are stored in the encrypted database settings ([0013](0013-secrets-and-ssh-keys.md)).
- Engines serve TLS themselves on fixed ports (`ClientPorts`): 25060 for SQL engines and MongoDB, 25061 for PgBouncer pools and for Valkey and Redis.
- Engines registered with `publicCertificate()` (Valkey and Redis) use a Let's Encrypt wildcard `*.db.<zone>`, issued over ACME DNS-01 and stored on the DNS zone, whenever the database has a DNS zone. Each node deploy issues the wildcard first when the zone has none, and each node records the fingerprint of the certificate it was given. The connection details switch to the public profile only once every node serves the current wildcard; if issuing fails, the nodes keep the private CA until the daily check succeeds.
- A daily check at 02:00 renews certificates that expire within 30 days, no longer cover every hostname or still name a hostname the database left, or that a node does not serve yet, on ready and degraded databases; an offline database is renewed by the first check after it serves again.

## Consequences

- Clients connect over TLS without the CA, as on DigitalOcean: PostgreSQL with `sslmode=require`, MySQL with `--ssl-mode=REQUIRED`, MariaDB with `--ssl` (11.4+ clients still verify the server through the password), MongoDB with `tlsAllowInvalidCertificates=true`, and Valkey and Redis with `--insecure` unless they serve a public wildcard, which clients verify with no flags. The connection details explain how to verify the server with the CA instead.
- MongoDB keeps the private CA even with a DNS zone, because its clients connect with the skip-verify option and its members reach each other on the client port, verifying that CA.
- Unencrypted MySQL and MariaDB client connections are still accepted, matching DigitalOcean's behaviour.

## Alternatives considered

- **Let's Encrypt for every engine:** dropped, because it made a domain and DNS provider mandatory. It was kept only for key-value engines.

## Evidence

- `app/Support/Tls/OwnCertificateAuthority.php`
- `app/Support/Databases/ClientPorts.php`
- `app/Services/Acme/LetsEncrypt.php`
- `app/Actions/Dns/IssueZoneCertificate.php`
- `app/Actions/Databases/Nodes/DeployNode.php`
- `app/Actions/Databases/Certificates/InstallNodeCertificate.php`
- `routes/console.php`
- `app/Actions/Databases/Certificates/QueueCertificateRenewal.php`
- `app/Actions/Databases/Certificates/IssueNodeCertificates.php`
