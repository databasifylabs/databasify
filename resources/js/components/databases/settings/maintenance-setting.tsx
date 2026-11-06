import { CalendarClock, Pencil } from 'lucide-react';
import { useState } from 'react';
import DatabaseSettingsController from '@/actions/App/Http/Controllers/Databases/DatabaseSettingsController';
import SettingRow from '@/components/databases/settings/setting-row';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { hourLabel, hoursOfDay } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import type { Database } from '@/types';

const days = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

export default function MaintenanceSetting({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const { maintenance } = database;
    const [editing, setEditing] = useState(false);
    const [day, setDay] = useState(String(maintenance.day));
    const [hour, setHour] = useState(String(maintenance.hour));
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        runAction(DatabaseSettingsController.maintenance(database.id), {
            data: { day: Number(day), hour: Number(hour) },
            success: 'Maintenance window saved',
            onSuccess: () => setEditing(false),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <SettingRow
            icon={CalendarClock}
            title="Maintenance window"
            description={`Updates within ${database.version_label} install automatically during this weekly hour, one node at a time.`}
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
                <div className="space-y-3">
                    <div className="flex flex-wrap gap-2">
                        <Select value={day} onValueChange={setDay}>
                            <SelectTrigger className="w-40" aria-label="Day">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {days.map((label, index) => (
                                    <SelectItem
                                        key={label}
                                        value={String(index)}
                                    >
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={hour} onValueChange={setHour}>
                            <SelectTrigger className="w-32" aria-label="Hour">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {hoursOfDay.map((entry) => (
                                    <SelectItem
                                        key={entry}
                                        value={String(entry)}
                                    >
                                        {hourLabel(entry)} UTC
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setEditing(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={save}>
                            Save window
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="space-y-1">
                    <p>
                        {days[maintenance.day]} {hourLabel(maintenance.hour)}–
                        {hourLabel((maintenance.hour + 1) % 24)} UTC
                    </p>
                    <p className="text-muted-foreground">
                        Next window starts{' '}
                        {new Date(maintenance.next_start_at).toLocaleString(
                            undefined,
                            {
                                weekday: 'short',
                                day: 'numeric',
                                month: 'short',
                                hour: '2-digit',
                                minute: '2-digit',
                            },
                        )}{' '}
                        your time.
                    </p>
                </div>
            )}
        </SettingRow>
    );
}
