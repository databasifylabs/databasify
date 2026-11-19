import { ArrowDownToLine } from 'lucide-react';
import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { remarkAlert } from 'remark-github-blockquote-alert';
import UpdateController from '@/actions/App/Http/Controllers/Updates/UpdateController';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { dayLabel, timeAgo } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import type { UpdateRelease } from '@/types';

const RELEASES_URL = 'https://github.com/databasifylabs/databasify/releases';

function UpdateAction({
    release,
    pinnedVersion,
}: {
    release: UpdateRelease;
    pinnedVersion: string | null;
}) {
    if (pinnedVersion) {
        return (
            <p className="text-sm text-muted-foreground">
                Pinned to {pinnedVersion} in /opt/databasify/.env
            </p>
        );
    }

    return (
        <ConfirmDialog
            trigger={<Button>Update to {release.version}</Button>}
            title={`Update Databasify to ${release.version}?`}
            description="Databasify pulls the new release and restarts. The app is offline for about a minute and running jobs finish first; your databases keep running."
            confirmLabel="Update"
            onConfirm={() => runAction(UpdateController.store())}
        />
    );
}

export default function UpdateAvailable({
    currentVersion,
    release,
    checkedAt,
    pinnedVersion,
}: {
    currentVersion: string;
    release: UpdateRelease;
    checkedAt: string | null;
    pinnedVersion: string | null;
}) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <ArrowDownToLine className="size-5" />
                    </div>
                    <div>
                        <div className="font-medium">
                            Databasify {release.version} is available
                        </div>
                        <div className="text-sm text-muted-foreground">
                            You're running {currentVersion} · Checked{' '}
                            {timeAgo(checkedAt)}
                        </div>
                    </div>
                </div>
                <UpdateAction release={release} pinnedVersion={pinnedVersion} />
            </div>
            <div className="mt-4 border-t pt-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm font-medium">
                        What's new in {release.version}
                    </span>
                    <div className="flex items-center gap-3 text-sm text-muted-foreground">
                        <span>Released {dayLabel(release.publishedAt)}</span>
                        <a
                            href={release.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline-offset-4 hover:underline"
                        >
                            View on GitHub ↗
                        </a>
                        <a
                            href={RELEASES_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline-offset-4 hover:underline"
                        >
                            All releases ↗
                        </a>
                    </div>
                </div>
                <div className="release-notes mt-2">
                    <Markdown remarkPlugins={[remarkGfm, remarkAlert]}>
                        {release.notes}
                    </Markdown>
                </div>
            </div>
        </div>
    );
}
