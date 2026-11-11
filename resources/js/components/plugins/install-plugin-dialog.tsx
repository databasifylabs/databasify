import { Form } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { useState } from 'react';
import PluginController from '@/actions/App/Http/Controllers/Plugins/PluginController';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import CommunityEngineWarning from '@/components/plugins/community-engine-warning';
import ZipDropzone from '@/components/plugins/zip-dropzone';

export default function InstallPluginDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus /> Install plugin
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Install plugin</DialogTitle>
                <DialogDescription>
                    Plugins add database engines, DNS and server providers. They
                    load right away, with no restart.
                </DialogDescription>
                <Form
                    {...PluginController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-5"
                >
                    {({ processing, errors, clearErrors }) => (
                        <>
                            <Tabs defaultValue="link" className="gap-4">
                                <TabsList className="w-full">
                                    <TabsTrigger value="link">
                                        From a link
                                    </TabsTrigger>
                                    <TabsTrigger value="upload">
                                        Upload a zip
                                    </TabsTrigger>
                                </TabsList>
                                <TabsContent
                                    value="link"
                                    className="grid gap-2"
                                >
                                    <Label htmlFor="plugin-source">
                                        GitHub repository or zip link
                                    </Label>
                                    <Input
                                        id="plugin-source"
                                        name="source"
                                        placeholder="owner/repo or https://example.com/plugin.zip"
                                        autoComplete="off"
                                        onChange={() =>
                                            clearErrors('community_engines')
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        A repository installs its latest
                                        release, or its default branch when it
                                        has none.
                                    </p>
                                    <InputError message={errors.source} />
                                </TabsContent>
                                <TabsContent
                                    value="upload"
                                    className="grid gap-2"
                                >
                                    <ZipDropzone
                                        id="plugin-archive"
                                        name="archive"
                                        hint="Up to 50 MB, with the plugin's composer.json at its root or in one top folder."
                                        onFileChange={() =>
                                            clearErrors('community_engines')
                                        }
                                    />
                                    <InputError message={errors.archive} />
                                </TabsContent>
                            </Tabs>
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
                                    <Download />
                                    {processing
                                        ? 'Installing…'
                                        : errors.community_engines
                                          ? 'Accept and install'
                                          : 'Install'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
