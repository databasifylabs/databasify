# Storage setup and backup alerts

## Add a storage destination

Choose **Storage → Add storage**, select a service and enter its HTTPS S3 API endpoint, signing region, bucket and access credentials. The selector provides examples and defaults; it does not create an account or bucket. Endpoint examples are placeholders and must be replaced with your actual endpoint. Bucket, endpoint and credentials stay editable.

Supported setup guidance includes Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi, MinIO and custom S3-compatible storage. Existing records retain their location and credentials when choosing guidance. Locations used by a database remain locked.

- [Amazon S3](https://docs.aws.amazon.com/AmazonS3/latest/userguide/create-bucket-overview.html): use the bucket's regional API endpoint and matching signing region.
- [Cloudflare R2](https://developers.cloudflare.com/r2/api/s3/api/): copy the S3 API endpoint from R2 settings and use signing region `auto`.
- [DigitalOcean Spaces](https://docs.digitalocean.com/reference/api/spaces/): use the regional API endpoint without the Space name, not the CDN endpoint.
- [Backblaze B2](https://www.backblaze.com/docs/cloud-storage-call-the-s3-compatible-api): use the S3 endpoint and matching region shown for the bucket.
- [Wasabi](https://docs.wasabi.com/docs/service-urls-for-wasabis-storage-regions): use the service URL matching the bucket's region.
- MinIO: use the HTTPS S3 API address, not the administration console; use the configured signing region, or `us-east-1`.

The existing connection check writes, reads and deletes a unique test object before saving. Keys remain encrypted. Use a key restricted to the intended backup bucket and required operations.

## Monitor backup health

The database **Backups** tab shows archive health, last base backup, kept backups, recovery coverage, schedule and the last status check. Archive health is not proof of a successful restore.

A daily base backup is overdue after 26 hours without a completed base backup. This allows two hours beyond the daily interval for completion. A new configuration without a first completed backup uses its creation time for the grace period. A long-running first backup may therefore require investigation even when still running. Existing engine errors take precedence over this overdue message. Existing detection of recovery coverage older than 15 minutes remains active.

Health transitions to failing raise one database email alert. Repeated failed checks do not repeat it. A transition from failing to healthy raises one recovery email. Error details remain in the authenticated Backups page rather than being copied into email. Configure the application's mail transport and run its monitoring/notification queues to deliver email; the local development default logs mail instead of sending it.

## Restore verification

Use the existing restore flow to create a separate database and verify its contents. Compare application data, schema and representative queries. A successful storage probe or archive status check alone does not verify recovery. Automated restore verification is not included in this change.

## FTP, FTPS and SFTP

Open **Storage → Add storage** and select the FTP, FTPS or SFTP service card. Cloud buckets and file servers share this single setup entry point and appear together in the Storage list. Old file-backup links redirect to Storage. S3 is not required for file servers. PostgreSQL, MySQL and MariaDB are supported as logical SQL export sources. MongoDB, Redis, Valkey and community engines are not offered by this export mode.

Enter the file server address, port, username, password and directory. For SFTP, also supply the verified server public host key (algorithm plus base64 key, without hostname or comment). FTPS means explicit TLS, normally on port 21, with certificate verification enabled. Plain FTP requires acknowledgement because it exposes connection credentials and traffic even though the exported backup file is encrypted.

The database host must have `rclone` and GnuPG installed. SFTP requires a version offering `--sftp-host-key-algorithms`; older distribution packages can lack this capability and the connection probe will refuse them. Use an up-to-date official rclone release with verified checksums when necessary. New Debian/Ubuntu provisioning includes the distribution rclone package; existing hosts can install tools with `sudo apt-get install rclone gnupg` and check `rclone help flags` for the required SFTP option. On other distributions, install these tools from trusted repositories or their official distribution channels before configuring a destination. The connection is tested from the database host with a unique write/read/delete probe.

Choose a daily UTC hour and a backup encryption passphrase of at least 16 characters. Keep that passphrase somewhere independent of Databasify. An app database backup alone is insufficient if the app encryption key is lost. When rotating an encryption passphrase, keep every older passphrase needed for retained files.

Each run creates a native SQL export on the database host, compresses it and encrypts it with GnuPG AES-256. The encrypted file is uploaded as `.part`, downloaded as a stream for SHA-256 verification, then renamed to its final name. A checksum sidecar and `.complete.json` manifest are uploaded last. A run succeeds only after all steps finish. Interrupted runs can leave partial files; only runs with the completion manifest should be considered completed.

Files are kept under `<directory>/databasify/<destination-id>/`. No remote retention or deletion is performed by this version. Removing the destination removes its local history and stored credentials; remote files remain. Locations with completed backups cannot be changed: create another destination instead. The UI lists the latest ten runs per destination. Keep an independent record of encryption passphrases and file locations.

The Laravel scheduler must run each minute and the `databases` queue needs a worker. A backup may run for up to approximately one hour; use the production Redis/Horizon queue configuration with its existing 3900-second retry period. A Windows web preview does not supply the production Linux queue runtime. Failures generate the existing database email notifications when mail delivery is configured.

These SQL exports are separate from S3 point-in-time recovery. They do not include continuous WAL/binlog coverage, app settings, SSH keys or the Databasify `APP_KEY`. MySQL/MariaDB consistency requires transactional tables and no concurrent schema changes; PostgreSQL exports are consistent per database, not a single cross-database transaction.

### Restore a file export

1. Copy the encrypted file and checksum sidecar to a trusted machine and run `sha256sum -c <file>.sha256`.
2. Run `gpg --output backup.sql.gz --decrypt <file>.sql.gz.gpg`; enter the matching encryption passphrase interactively.
3. Decompress with `gzip -d backup.sql.gz`.
4. Restore into a **separate, isolated database server** using the matching database engine and supported version. PostgreSQL uses `psql`, MySQL uses `mysql` and MariaDB uses `mariadb`. Whole-server exports include database and role/account definitions: review conflicts before importing into any existing cluster.
5. Check schemas, row counts and representative application queries before using the restored server.

The application verifies uploads but does not automatically run this restore procedure. Do not import a whole-server SQL export into a live production server merely to test it.
