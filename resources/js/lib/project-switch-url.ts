import { projectIdInPath } from '@/lib/project-url-defaults';
import { dashboard } from '@/routes';
import { index as databasesIndex } from '@/routes/databases';
import { index as dnsIndex } from '@/routes/dns';
import { index as providersIndex } from '@/routes/providers';
import { index as serversIndex } from '@/routes/servers';
import { index as storageIndex } from '@/routes/storage-providers';

type SectionUrl = (args: { project: string }) => string;

const sectionIndexes = new Map<string, SectionUrl>([
    ['databases', databasesIndex.url],
    ['dns', dnsIndex.url],
    ['providers', providersIndex.url],
    ['servers', serversIndex.url],
    ['storage-providers', storageIndex.url],
]);

function sectionIn(pathname: string): string | undefined {
    if (projectIdInPath(pathname) === undefined) {
        return undefined;
    }

    return pathname.split('/')[2];
}

export function switchUrlFor(pathname: string, projectId: string): string {
    const indexOf =
        sectionIndexes.get(sectionIn(pathname) ?? '') ?? dashboard.url;

    return indexOf({ project: projectId });
}
