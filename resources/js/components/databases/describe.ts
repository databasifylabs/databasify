import { formatMemory, pluralize } from '@/lib/format';
import type { DatabaseSummary, DatabaseNode } from '@/types';

export function configurationOf(database: DatabaseSummary): string {
    const standbys = membersOf(database).length - 1;

    return standbys > 0
        ? `Primary + ${pluralize(standbys, 'standby')}`
        : 'Primary only';
}

export function membersOf(database: DatabaseSummary): DatabaseNode[] {
    return database.nodes.filter((node) => node.kind === 'member');
}

export function readOnlyNodesOf(database: DatabaseSummary): DatabaseNode[] {
    return database.nodes.filter((node) => node.kind === 'read_only');
}

export function versionOf(database: DatabaseSummary): string {
    return [database.engine_label, database.engine_version]
        .filter(Boolean)
        .join(' ');
}

export function specsOf(database: DatabaseSummary): string {
    const node = database.nodes[0];

    return [
        node ? `${formatMemory(node.memory_mb)} RAM` : null,
        node?.cpus ? `${node.cpus} vCPU` : null,
        node?.disk_gb ? `${node.disk_gb} GB disk` : null,
        versionOf(database),
    ]
        .filter(Boolean)
        .join(' / ');
}

export function updateActionOf(database: DatabaseSummary): string {
    return database.available_update_refreshes_image
        ? 'Refresh image'
        : `Install ${database.available_version}`;
}

export function updateTitleOf(database: DatabaseSummary): string {
    return database.available_update_refreshes_image
        ? `Refresh the ${database.engine_label} ${database.available_version} image on ${database.name}?`
        : `Install ${database.engine_label} ${database.available_version} on ${database.name}?`;
}

export function updateNoticeOf(database: DatabaseSummary): string {
    return database.available_update_refreshes_image
        ? `A rebuilt ${database.engine_label} ${database.available_version} image is available for ${database.name}`
        : `${database.engine_label} ${database.available_version} is available for ${database.name}`;
}
