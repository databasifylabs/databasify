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

Direct file-transfer destinations are not implemented by this change. They cannot be supplied as S3 endpoints. pgBackRest, MongoDB PBM and other engine-native archive tools have storage requirements beyond a generic upload operation. Before adding file-transfer options, agree on supported secondary-copy or export semantics and test real restore paths for every supported engine. The upstream issue proposal tracks this separately.
