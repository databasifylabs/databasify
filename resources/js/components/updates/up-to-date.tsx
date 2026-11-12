import { RefreshCw, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dayLabel, timeAgo } from '@/lib/format';
import type { UpdateRelease } from '@/types';

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <span className="text-muted-foreground">{label}</span>{' '}
            <span className="font-medium">{value}</span>
        </div>
    );
}

export default function UpToDate({
    currentVersion,
    release,
    checkedAt,
    nextCheckAt,
    onCheck,
}: {
    currentVersion: string;
    release: UpdateRelease | null;
    checkedAt: string | null;
    nextCheckAt: string;
    onCheck: () => void;
}) {
    return (
        <div className="flex min-h-[420px] flex-col items-center justify-center gap-4 rounded-xl border px-6 text-center">
            <div className="flex size-12 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-500">
                <ShieldCheck className="size-6" />
            </div>
            <div className="space-y-1">
                <div className="text-lg font-semibold">You're up to date</div>
                <p className="text-sm text-muted-foreground">
                    Databasify {currentVersion} is the latest release.
                </p>
            </div>
            <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm">
                <Fact label="Running" value={currentVersion} />
                {release && (
                    <Fact
                        label="Released"
                        value={dayLabel(release.publishedAt)}
                    />
                )}
                <Fact label="Last checked" value={timeAgo(checkedAt)} />
                <Fact label="Next check" value={timeAgo(nextCheckAt)} />
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Button variant="outline" onClick={onCheck}>
                    <RefreshCw />
                    Check now
                </Button>
                {release && (
                    <Button variant="outline" asChild>
                        <a
                            href={release.url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            What's in {release.version} ↗
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}
