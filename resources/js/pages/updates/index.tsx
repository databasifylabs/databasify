import { Head, usePoll } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect } from 'react';
import UpdateController from '@/actions/App/Http/Controllers/Updates/UpdateController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import UpToDate from '@/components/updates/up-to-date';
import UpdateAvailable from '@/components/updates/update-available';
import UpdateFailed from '@/components/updates/update-failed';
import UpdateProgress, {
    UpdateStarting,
} from '@/components/updates/update-progress';
import { runAction } from '@/lib/run-action';
import type { UpdatesPageProps } from '@/types';

const POLL_MILLISECONDS = 2000;

function checkNow(): void {
    runAction(UpdateController.check());
}

function useStatusPolling(running: boolean): void {
    const { start, stop } = usePoll(
        POLL_MILLISECONDS,
        {
            onHttpException: () => false,
            onNetworkError: () => false,
        },
        { autoStart: false },
    );

    useEffect(() => {
        if (!running) {
            stop();

            return;
        }

        start();

        return stop;
    }, [running, start, stop]);
}

function UpdatesBody(props: UpdatesPageProps) {
    const { status, release, updateAvailable } = props;

    if (status?.state === 'running') {
        return <UpdateProgress status={status} />;
    }

    if (props.updateRequested) {
        return <UpdateStarting version={release?.version ?? null} />;
    }

    if (
        status?.state === 'failed' &&
        updateAvailable &&
        status.to === release?.version
    ) {
        return <UpdateFailed status={status} logTail={props.logTail} />;
    }

    if (release && updateAvailable) {
        return (
            <UpdateAvailable
                currentVersion={props.currentVersion}
                release={release}
                checkedAt={props.checkedAt}
                pinnedVersion={props.pinnedVersion}
            />
        );
    }

    return (
        <UpToDate
            currentVersion={props.currentVersion}
            release={release}
            checkedAt={props.checkedAt}
            nextCheckAt={props.nextCheckAt}
            onCheck={checkNow}
        />
    );
}

export default function UpdatesIndex(props: UpdatesPageProps) {
    const updating = props.status?.state === 'running' || props.updateRequested;
    const upToDate = !props.updateAvailable && !updating;

    useStatusPolling(updating);

    return (
        <>
            <Head title="Updates" />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Updates"
                        description="Databasify checks for a new release once a day. Updating keeps your databases, servers and settings."
                    />
                    {!upToDate && (
                        <Button variant="outline" onClick={checkNow}>
                            <RefreshCw />
                            Check now
                        </Button>
                    )}
                </div>
                <UpdatesBody {...props} />
            </div>
        </>
    );
}

UpdatesIndex.layout = {
    breadcrumbs: [{ title: 'Updates', href: UpdateController.index() }],
};
