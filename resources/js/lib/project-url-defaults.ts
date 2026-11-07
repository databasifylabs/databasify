import type { Page, SharedPageProps } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { setUrlDefaults } from '@/wayfinder';

const projectSegment =
    /^\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:\/|$)/i;

let currentProjectId: string | undefined;
let listening = false;

export function projectIdInPath(pathname: string): string | undefined {
    return projectSegment.exec(pathname)?.[1];
}

function projectIdInLocation(): string | undefined {
    if (typeof window === 'undefined') {
        return undefined;
    }

    return projectIdInPath(window.location.pathname);
}

function rememberProjectOf(page: Page<SharedPageProps>): void {
    currentProjectId = page.props.currentProject?.id;
}

export function trackProjectUrlDefaults(
    page: Page<SharedPageProps>,
    ssr: boolean,
): void {
    rememberProjectOf(page);
    setUrlDefaults(() => ({
        project: projectIdInLocation() ?? currentProjectId,
    }));

    if (ssr || listening) {
        return;
    }

    listening = true;
    router.on('navigate', (event) => rememberProjectOf(event.detail.page));
}
