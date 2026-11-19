export const storagePresets = {
    custom: {
        label: 'Custom S3-compatible storage',
        endpoint: 'https://s3.example.com',
        region: '',
        pathStyle: false,
        hint: 'Use the HTTPS S3 API endpoint and signing region supplied by your storage provider.',
    },
    aws: {
        label: 'Amazon S3',
        endpoint: 'https://s3.us-east-1.amazonaws.com',
        region: 'us-east-1',
        pathStyle: false,
        hint: 'Use the regional S3 API endpoint for your bucket. Change both the endpoint and region for a different AWS region.',
    },
    r2: {
        label: 'Cloudflare R2',
        endpoint: 'https://<account-id>.r2.cloudflarestorage.com',
        region: 'auto',
        pathStyle: true,
        hint: 'Copy the S3 API endpoint from your R2 bucket settings. Use an R2 access key pair and the region auto.',
    },
    spaces: {
        label: 'DigitalOcean Spaces',
        endpoint: 'https://nyc3.digitaloceanspaces.com',
        region: 'nyc3',
        pathStyle: false,
        hint: 'Use your regional Spaces API endpoint, without the Space name. Do not use the CDN endpoint.',
    },
    b2: {
        label: 'Backblaze B2',
        endpoint: 'https://s3.us-west-004.backblazeb2.com',
        region: 'us-west-004',
        pathStyle: false,
        hint: 'Copy your bucket S3 endpoint and matching region from Backblaze. Use the application key ID and application key.',
    },
    wasabi: {
        label: 'Wasabi',
        endpoint: 'https://s3.us-east-1.wasabisys.com',
        region: 'us-east-1',
        pathStyle: false,
        hint: 'Use the Wasabi service URL and signing region that match your bucket location.',
    },
    minio: {
        label: 'MinIO',
        endpoint: 'https://minio.example.com:9000',
        region: 'us-east-1',
        pathStyle: true,
        hint: 'Use the HTTPS S3 API endpoint, not the management console. Use your configured region, or us-east-1.',
    },
} as const;

export type StoragePreset = keyof typeof storagePresets;
