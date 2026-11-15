# 0039. The MongoDB default user can serve as a continuous migration source

- Status: Accepted
- Date: 2026-11-15

## Context

ADR 0013 gives the default user of every in-app database the rights a migration source needs, and for MongoDB it named the `read` role on `local`. Continuous MongoDB migrations run Percona ClusterSync (PCSM), whose source user needs the `backup`, `clusterMonitor` and `readAnyDatabase` roles (PCSM authentication docs: https://docs.percona.com/percona-clustersync-for-mongodb/install/authentication.html). Without `backup`, ClusterSync fails right after its clone starts (`appendOplogNote` unauthorized), so an in-app MongoDB database could not be the source of a continuous migration. The owner approved granting these roles.

## Decision

- The MongoDB default user holds `backup`, `clusterMonitor` and `readAnyDatabase` on `admin` (the `sourceRoles` list in `engines/mongodb/_roles`).
- New databases: the default user is created with them at bootstrap. Every time the driver rewrites the default user's roles (`createUser`, `grant`, for example when a database is created or a migration records its databases), `MongoDbAccounts` adds them, so the default user never loses them. Before this, creating a database replaced the default user's roles with `readWrite` grants only (`updateUser`), so roles granted by hand were silently dropped and the source check then offered only a one-time copy. Other users get them only when asked for replication.
- Existing databases: every image rollout (upgrade or maintenance, `RollOutImage`) ends with `GrantMigrationSourceRoles`. It adds the roles to the default user with `grantRolesToUser`, which only adds roles and never replaces or removes any. This is best effort: a failure writes a failed "Grant migration source roles to the default user" server log, the rollout still succeeds, and the next upgrade tries again.
- Only drivers whose accounts implement `MigrationSourceRoles` take this step. Today that is MongoDB only. The other engines' source rights stay as ADR 0013 states.

## Consequences

- An in-app MongoDB database reached as its default user passes the source check and can be followed continuously.
- The default user can read every database on its server, plus the oplog, and can run backup commands. It sees data outside the databases it owns. Anyone holding the default user's password has that access.
- Existing databases get the roles at their next upgrade or maintenance, not at once.

## Alternatives considered

- A separate migration user per database: one more secret to create, rotate and show. Rejected for now.
- Re-syncing the default user's database grants on every rollout through `grant()`: that replaces the user's roles and, for SQL engines, revokes grants on databases outside the recorded list. Rejected because it could take access away. The additive `grantRolesToUser` cannot.

## Evidence

- `resources/views/engines/mongodb/_roles.blade.php`
- `resources/views/engines/mongodb/accounts/grant-source-roles.blade.php`
- `resources/views/engines/mongodb/accounts/create-user.blade.php`
- `app/Engines/Drivers/MongoDb/MongoDbAccounts.php`
- `app/Engines/Contracts/MigrationSourceRoles.php`
- `app/Actions/Databases/Accounts/GrantMigrationSourceRoles.php`
- `app/Actions/Databases/Upgrades/RollOutImage.php`
