import { Head } from '@inertiajs/react';
import { Puzzle } from 'lucide-react';
import PluginController from '@/actions/App/Http/Controllers/Plugins/PluginController';
import BrandIcon from '@/components/brand-icon';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InstallPluginDialog from '@/components/plugins/install-plugin-dialog';
import UpdatePluginDialog from '@/components/plugins/update-plugin-dialog';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { runAction } from '@/lib/run-action';
import type { InstalledPlugin } from '@/types';

function Plugin({ plugin }: { plugin: InstalledPlugin }) {
    return (
        <div className="space-y-2 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <BrandIcon group="plugins" src={plugin.icon} />
                    <div className="min-w-0">
                        <div className="font-medium">
                            {plugin.name}{' '}
                            <Badge variant="outline">{plugin.version}</Badge>
                        </div>
                        <div className="truncate font-mono text-xs text-muted-foreground">
                            {plugin.source}
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <UpdatePluginDialog plugin={plugin} />
                    <Switch
                        checked={plugin.enabled}
                        aria-label={`Enable ${plugin.name}`}
                        onCheckedChange={(enabled) =>
                            runAction(PluginController.update(plugin.id), {
                                data: { enabled },
                                success: `${enabled ? 'Enabled' : 'Disabled'} ${plugin.name}`,
                            })
                        }
                    />
                    <ConfirmDialog
                        trigger={
                            <DeleteButton
                                aria-label={`Uninstall ${plugin.name}`}
                            />
                        }
                        title={`Uninstall ${plugin.name}?`}
                        description="Databasify runs its uninstall step, rolls back its database tables and deletes its files. Anything you created with it stops working."
                        confirmLabel="Uninstall"
                        destructive
                        onConfirm={() =>
                            runAction(PluginController.destroy(plugin.id), {
                                success: `Uninstalled ${plugin.name}`,
                            })
                        }
                    />
                </div>
            </div>
            {plugin.error && (
                <p className="text-sm text-destructive">
                    Failed to load: {plugin.error}
                </p>
            )}
        </div>
    );
}

export default function PluginsIndex({
    plugins,
}: {
    plugins: InstalledPlugin[];
}) {
    return (
        <>
            <Head title="Plugins" />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Plugins"
                        description="Add database engines, DNS and server providers from a GitHub repository, a zip link or a zip file."
                    />
                    <InstallPluginDialog />
                </div>

                {plugins.length === 0 ? (
                    <EmptyState
                        icon={Puzzle}
                        title="No plugins yet"
                        description="Installed plugins load on every server request and worker, with no restart needed."
                    >
                        <InstallPluginDialog />
                    </EmptyState>
                ) : (
                    <div className="space-y-3">
                        {plugins.map((plugin) => (
                            <Plugin key={plugin.id} plugin={plugin} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

PluginsIndex.layout = {
    breadcrumbs: [{ title: 'Plugins', href: PluginController.index() }],
};
