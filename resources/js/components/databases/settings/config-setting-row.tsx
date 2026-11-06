import { useState } from 'react';
import DatabaseSettingsController from '@/actions/App/Http/Controllers/Databases/DatabaseSettingsController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { runAction } from '@/lib/run-action';
import type { Database, ConfigField } from '@/types';

function configWith(
    database: Database,
    key: string,
    value: string | null,
): Record<string, string> {
    const { [key]: _removed, ...rest } = database.config;

    return value === null ? rest : { ...rest, [key]: value };
}

function FieldEditor({
    field,
    value,
    onChange,
}: {
    field: ConfigField;
    value: string;
    onChange: (value: string) => void;
}) {
    if (field.multiple) {
        const selected = value.split(',').filter(Boolean);
        const toggle = (option: string) =>
            onChange(
                field.options
                    .filter((entry) =>
                        entry === option
                            ? !selected.includes(entry)
                            : selected.includes(entry),
                    )
                    .join(','),
            );

        return (
            <div className="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2">
                {field.options.map((option) => (
                    <label
                        key={option}
                        className="flex items-center gap-2 font-mono text-xs"
                    >
                        <Checkbox
                            checked={selected.includes(option)}
                            onCheckedChange={() => toggle(option)}
                        />
                        {option}
                    </label>
                ))}
            </div>
        );
    }

    if (field.options.length > 0) {
        return (
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger className="w-full font-mono sm:w-64">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {field.options.map((option) => (
                        <SelectItem
                            key={option}
                            value={option}
                            className="font-mono"
                        >
                            {option}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    return (
        <Input
            value={value}
            onChange={(event) => onChange(event.target.value)}
            placeholder={field.default || 'Empty'}
            inputMode={field.min === null ? undefined : 'decimal'}
            className="w-full font-mono sm:w-64"
            autoComplete="off"
        />
    );
}

function rangeOf(field: ConfigField): string | null {
    if (field.min === null || field.max === null) {
        return null;
    }

    return `Between ${field.min} and ${field.max}${field.unit ? ` ${field.unit}` : ''}.`;
}

function withUnit(value: string, field: ConfigField): string {
    return field.unit && /^-?[0-9.]+$/.test(value)
        ? `${value} ${field.unit}`
        : value;
}

function sourceOf(custom: boolean, field: ConfigField): string {
    if (custom) {
        return 'Custom';
    }

    return field.tuned ? 'Auto' : 'Default';
}

export default function ConfigSettingRow({
    database,
    field,
    busy,
}: {
    database: Database;
    field: ConfigField;
    busy: boolean;
}) {
    const current = database.config[field.key];
    const custom = current !== undefined;
    const fallback = database.tuned_config[field.key] ?? field.default;
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(current ?? fallback);
    const [saving, setSaving] = useState(false);
    const range = rangeOf(field);

    const save = (next: string | null) => {
        setSaving(true);
        runAction(DatabaseSettingsController.config(database.id), {
            data: { config: configWith(database, field.key, next) },
            success: `Applying ${field.label.toLowerCase()}`,
            onSuccess: () => setEditing(false),
            onFinish: () => setSaving(false),
        });
    };

    const startEditing = () => {
        setValue(current ?? fallback);
        setEditing(true);
    };

    return (
        <div className="grid grid-cols-1 gap-3 py-5 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] md:gap-6">
            <div className="space-y-1">
                <div className="font-medium">{field.label}</div>
                <div className="font-mono text-xs text-muted-foreground">
                    {field.key}
                </div>
            </div>
            <div className="min-w-0 space-y-3">
                <p className="text-sm text-muted-foreground">
                    {field.description}
                    {range && ` ${range}`}
                </p>
                {editing ? (
                    <div className="space-y-3">
                        <FieldEditor
                            field={field}
                            value={value}
                            onChange={setValue}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                disabled={saving}
                                onClick={() => save(value.trim() || null)}
                            >
                                {field.apply === 'restart'
                                    ? 'Save and restart'
                                    : 'Save'}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </Button>
                            {custom && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={saving}
                                    onClick={() => save(null)}
                                >
                                    {field.tuned
                                        ? 'Reset to auto'
                                        : 'Reset to default'}
                                </Button>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <code className="rounded-md border bg-muted px-2 py-1 font-mono text-xs break-all">
                            {withUnit(custom ? current : fallback, field) ||
                                'Off'}
                        </code>
                        <Badge variant={custom ? 'default' : 'secondary'}>
                            {sourceOf(custom, field)}
                        </Badge>
                        {field.apply === 'restart' && (
                            <Badge variant="outline">Restart</Badge>
                        )}
                    </div>
                )}
            </div>
            <div className="md:justify-self-end">
                {!editing && (
                    <Button
                        variant="link"
                        className="h-auto p-0"
                        disabled={busy}
                        onClick={startEditing}
                    >
                        Edit
                    </Button>
                )}
            </div>
        </div>
    );
}
