import { Form, Link } from '@inertiajs/react';
import { Globe, Info, Pencil, Trash2, Type } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import DatabaseNodeController from '@/actions/App/Http/Controllers/Databases/DatabaseNodeController';
import SettingRow from '@/components/databases/settings/setting-row';
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { timeAgo } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import type { Database, DatabaseNode } from '@/types';

function NameSetting({
    database,
    node,
    busy,
}: {
    database: Database;
    node: DatabaseNode;
    busy: boolean;
}) {
    const [editing, setEditing] = useState(false);

    return (
        <SettingRow
            icon={Type}
            title="Name"
            description="How this node appears in Databasify."
            action={
                !editing && (
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Edit
                    </Button>
                )
            }
        >
            {editing ? (
                <Form
                    {...DatabaseNodeController.update.form({
                        database: database.id,
                        node: node.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        toast.success('Node renamed');
                        setEditing(false);
                    }}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Input
                                name="name"
                                defaultValue={node.name}
                                maxLength={60}
                                aria-label="Name"
                            />
                            <InputError message={errors.name} />
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setEditing(false)}
                                >
                                    Cancel
                                </Button>
                                <Button disabled={processing}>Save name</Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <span className="font-mono">{node.name}</span>
            )}
        </SettingRow>
    );
}

function RemoveNode({
    database,
    node,
}: {
    database: Database;
    node: DatabaseNode;
}) {
    const [force, setForce] = useState(node.role === 'unknown');

    return (
        <ConfirmDialog
            trigger={
                <Button variant="destructive">
                    <Trash2 />
                    Remove
                </Button>
            }
            title={`Remove ${node.name}?`}
            description={
                <p>
                    The node leaves {database.name} and its copy of the data is
                    erased. Apps reading from its host stop getting answers.
                </p>
            }
            confirmLabel="Remove node"
            destructive
            typeToConfirm={node.name}
            onConfirm={() =>
                runAction(
                    DatabaseNodeController.destroy({
                        database: database.id,
                        node: node.id,
                    }),
                    { data: { force }, success: `Removing ${node.name}` },
                )
            }
        >
            <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                <Switch checked={force} onCheckedChange={setForce} />
                <span>
                    <span className="font-medium">Force</span>
                    <span className="block text-muted-foreground">
                        Remove the node even if its server can't be reached.
                    </span>
                </span>
            </label>
        </ConfirmDialog>
    );
}

export default function NodeSettingsTab({
    database,
    node,
    busy,
}: {
    database: Database;
    node: DatabaseNode;
    busy: boolean;
}) {
    return (
        <div className="space-y-6">
            <Card className="py-0">
                <CardContent className="divide-y">
                    <SettingRow
                        icon={Info}
                        title="Node"
                        description="General details."
                    >
                        <dl className="grid grid-cols-[8rem_1fr] gap-y-1.5">
                            <dt className="text-muted-foreground">Primary</dt>
                            <dd>
                                <Link
                                    href={DatabaseController.show({
                                        database: database.id,
                                    })}
                                    className="hover:underline"
                                >
                                    {database.name}
                                </Link>
                            </dd>
                            <dt className="text-muted-foreground">Server</dt>
                            <dd>{node.server_name}</dd>
                            <dt className="text-muted-foreground">Port</dt>
                            <dd className="font-mono">{database.port}</dd>
                            <dt className="text-muted-foreground">Created</dt>
                            <dd>{timeAgo(node.created_at)}</dd>
                        </dl>
                    </SettingRow>

                    <NameSetting database={database} node={node} busy={busy} />

                    <SettingRow
                        icon={Globe}
                        title="Hostnames"
                        description="From the node's name and its database's domain."
                    >
                        {node.domain ? (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                                <dt className="text-muted-foreground">
                                    Public
                                </dt>
                                <dd className="truncate font-mono">
                                    {node.domain}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Private network
                                </dt>
                                <dd className="truncate font-mono">
                                    {node.private_domain}
                                </dd>
                            </dl>
                        ) : (
                            <p className="text-muted-foreground">
                                None yet. Pick a domain in the database's
                                settings and this node gets {node.name}.your
                                domain.
                            </p>
                        )}
                    </SettingRow>
                </CardContent>
            </Card>

            <Card className="border-destructive/40 py-0">
                <CardContent>
                    <SettingRow
                        icon={Trash2}
                        title="Remove this node"
                        description="Erases this node's copy of the data. The primary and standbys keep running."
                        action={<RemoveNode database={database} node={node} />}
                    />
                </CardContent>
            </Card>
        </div>
    );
}
