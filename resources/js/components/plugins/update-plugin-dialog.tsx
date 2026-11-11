import { Form } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import PluginController from '@/actions/App/Http/Controllers/Plugins/PluginController';
import InputError from '@/components/input-error';
import CommunityEngineWarning from '@/components/plugins/community-engine-warning';
import ZipDropzone from '@/components/plugins/zip-dropzone';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { InstalledPlugin } from '@/types';

export default function UpdatePluginDialog({
    plugin,
}: {
    plugin: InstalledPlugin;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    <RefreshCw /> Update
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Update {plugin.name}</DialogTitle>
                <DialogDescription>
                    Version {plugin.version} is installed. Its accounts and
                    settings are kept.
                </DialogDescription>
                <Form
                    {...PluginController.upgrade.form(plugin.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-5"
                >
                    {({ processing, errors, clearErrors }) => (
                        <>
                            {!plugin.uploaded && (
                                <p className="rounded-xl border bg-muted/30 p-3 text-sm">
                                    Leave the zip empty to download the latest
                                    from{' '}
                                    <span className="font-mono">
                                        {plugin.source}
                                    </span>
                                    .
                                </p>
                            )}
                            <ZipDropzone
                                id={`plugin-update-${plugin.id}`}
                                name="archive"
                                hint={
                                    plugin.uploaded
                                        ? 'Upload the newer zip of this plugin, up to 50 MB.'
                                        : 'Or upload a newer zip of this plugin, up to 50 MB.'
                                }
                                onFileChange={() =>
                                    clearErrors('community_engines')
                                }
                            />
                            <InputError
                                message={errors.source ?? errors.archive}
                            />
                            <CommunityEngineWarning
                                message={errors.community_engines}
                            />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button disabled={processing}>
                                    <RefreshCw />
                                    {processing
                                        ? 'Updating…'
                                        : errors.community_engines
                                          ? 'Accept and update'
                                          : 'Update'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
