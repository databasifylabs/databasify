import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import ExtensionPackageController from '@/actions/App/Http/Controllers/Databases/ExtensionPackageController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { getJson } from '@/lib/http';
import { runAction } from '@/lib/run-action';
import type { Database, ExtensionPackage, PackageCandidate } from '@/types';

export function CheckNewerVersionDialog({
    database,
    entry,
    busy,
}: {
    database: Database;
    entry: ExtensionPackage;
    busy: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [candidate, setCandidate] = useState<PackageCandidate | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const check = () => {
        setCandidate(null);
        setError(null);
        void getJson<PackageCandidate>(
            ExtensionPackageController.candidate.url({
                database: database.id,
                package: entry.id,
            }),
        )
            .then(setCandidate)
            .catch((reason: Error) => setError(reason.message));
    };

    const newer =
        candidate !== null &&
        candidate.candidate !== null &&
        candidate.candidate !== candidate.installed;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    check();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    disabled={busy}
                    aria-label={`Check ${entry.request} for a newer version`}
                >
                    <RefreshCw />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Check for a newer version</DialogTitle>
                    <DialogDescription>
                        Asks the package's repository for the newest{' '}
                        {entry.request}. The extension stays enabled.
                        Afterwards, click Update on each schema that uses it.
                    </DialogDescription>
                </DialogHeader>
                {candidate === null && error === null && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner className="size-4" />
                        Checking…
                    </div>
                )}
                {error !== null && (
                    <p className="text-sm text-destructive">{error}</p>
                )}
                {candidate !== null && (
                    <p className="text-sm">
                        {newer
                            ? `${candidate.package} ${candidate.installed ?? 'not installed'} → ${candidate.candidate}`
                            : `You have the newest version${candidate.installed ? ` (${candidate.installed})` : ''}.`}
                    </p>
                )}
                <div className="flex justify-end">
                    <Button
                        disabled={busy || saving || !newer}
                        onClick={() => {
                            setSaving(true);
                            runAction(
                                ExtensionPackageController.update({
                                    database: database.id,
                                    package: entry.id,
                                }),
                                {
                                    success: `Updating ${entry.request}`,
                                    onSuccess: () => setOpen(false),
                                    onFinish: () => setSaving(false),
                                },
                            );
                        }}
                    >
                        {saving && <Spinner />}
                        Install
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
