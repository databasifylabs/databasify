export type UpdateRelease = {
    version: string;
    publishedAt: string;
    url: string;
    notesHtml: string;
};

export type UpdateStepState =
    | 'pending'
    | 'running'
    | 'done'
    | 'failed'
    | 'skipped';

export type UpdateStep = {
    title: string;
    state: UpdateStepState;
};

export type UpdateStatus = {
    state: 'running' | 'succeeded' | 'failed';
    from: string;
    to: string;
    startedAt: string;
    finishedAt: string | null;
    rolledBack: boolean;
    message: string;
    steps: UpdateStep[];
};

export type UpdatesPageProps = {
    currentVersion: string;
    release: UpdateRelease | null;
    checkedAt: string | null;
    nextCheckAt: string;
    updateAvailable: boolean;
    pinnedVersion: string | null;
    updaterInstalled: boolean;
    updateRequested: boolean;
    status: UpdateStatus | null;
    logTail: string[];
};
