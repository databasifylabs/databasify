import { Gauge, Search, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import ConfirmDialog from '@/components/confirm-dialog';
import ConfigSettingRow from '@/components/databases/settings/config-setting-row';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { runAction } from '@/lib/run-action';
import type { ConfigField, Database } from '@/types';

function matches(field: ConfigField, query: string): boolean {
    const needle = query.trim().toLowerCase();

    return (
        needle === '' ||
        field.label.toLowerCase().includes(needle) ||
        field.key.toLowerCase().includes(needle)
    );
}

function RetuneButton({
    database,
    busy,
    variant,
}: {
    database: Database;
    busy: boolean;
    variant: 'default' | 'outline';
}) {
    return (
        <ConfirmDialog
            trigger={
                <Button size="sm" variant={variant} disabled={busy}>
                    <Gauge />
                    Retune
                </Button>
            }
            title={`Retune ${database.name} for its servers?`}
            description="Databasify reads the CPU and memory of every node again and resizes the auto values to match. Nodes restart one at a time, standbys first. Values you set yourself are kept."
            confirmLabel="Retune"
            onConfirm={() =>
                runAction(
                    DatabaseOperationController.retune({
                        database: database.id,
                    }),
                    {
                        success: 'Retuning started',
                    },
                )
            }
        />
    );
}

export default function ConfigCard({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const [query, setQuery] = useState('');
    const fields = database.config_fields.filter((field) =>
        matches(field, query),
    );

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="flex flex-wrap items-start justify-between gap-4 border-b py-5">
                <div className="space-y-1.5">
                    <CardTitle className="flex items-center gap-2">
                        <SlidersHorizontal className="size-4 text-muted-foreground" />
                        Advanced configuration
                    </CardTitle>
                    <CardDescription>
                        Auto values are sized from the CPU and memory of each
                        node. Changes apply to every node in {database.name};
                        settings marked Restart restart the nodes one at a time.
                    </CardDescription>
                </div>
                <RetuneButton
                    database={database}
                    busy={busy}
                    variant="outline"
                />
            </CardHeader>
            <CardContent className="space-y-4 pt-5">
                {database.retune_needed && (
                    <Alert>
                        <Gauge />
                        <AlertTitle>
                            A server of {database.name} was resized
                        </AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                            <span>
                                Retune to size the auto values for the new CPU
                                and memory.
                            </span>
                            <RetuneButton
                                database={database}
                                busy={busy}
                                variant="default"
                            />
                        </AlertDescription>
                    </Alert>
                )}
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search by name"
                        className="pl-9"
                        type="search"
                    />
                </div>
                <div className="divide-y">
                    {fields.map((field) => (
                        <ConfigSettingRow
                            key={field.key}
                            database={database}
                            field={field}
                            busy={busy}
                        />
                    ))}
                    {fields.length === 0 && (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            No setting matches “{query}”.
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
