import { capitalize } from '@/lib/format';

export type StatusTone =
    | 'success'
    | 'progress'
    | 'warning'
    | 'danger'
    | 'neutral';

const statuses: Record<string, { label: string; tone: StatusTone }> = {
    ready: { label: 'Online', tone: 'success' },
    healthy: { label: 'Healthy', tone: 'success' },
    failing: { label: 'Failing', tone: 'danger' },
    succeeded: { label: 'Completed', tone: 'success' },
    completed: { label: 'Completed', tone: 'success' },
    syncing: { label: 'Syncing', tone: 'success' },
    running: { label: 'Running', tone: 'progress' },
    creating: { label: 'Creating', tone: 'progress' },
    provisioning: { label: 'Setting up', tone: 'progress' },
    copying: { label: 'Copying', tone: 'progress' },
    queued: { label: 'Queued', tone: 'progress' },
    pending: { label: 'Pending', tone: 'progress' },
    degraded: { label: 'Degraded', tone: 'warning' },
    offline: { label: 'Offline', tone: 'danger' },
    connected: { label: 'Connected', tone: 'success' },
    joining: { label: 'Joining', tone: 'progress' },
    out_of_sync: { label: 'Out of sync', tone: 'warning' },
    unreachable: { label: 'Unreachable', tone: 'danger' },
    failed: { label: 'Failed', tone: 'danger' },
    stopped: { label: 'Stopped', tone: 'neutral' },
    unknown: { label: 'Unknown', tone: 'neutral' },
};

export function statusLabel(status: string): string {
    return statuses[status]?.label ?? capitalize(status);
}

export function statusTone(status: string): StatusTone {
    return statuses[status]?.tone ?? 'neutral';
}

export function isWorking(status: string): boolean {
    return status === 'queued' || status === 'running';
}
