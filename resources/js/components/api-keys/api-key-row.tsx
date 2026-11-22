import { KeyRound } from 'lucide-react';
import ApiKeyController from '@/actions/App/Http/Controllers/ApiKeys/ApiKeyController';
import { areaIcons, levelColors } from '@/components/api-keys/area-icons';
import ConfirmDialog from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { runAction } from '@/lib/run-action';
import { cn } from '@/lib/utils';
import type { AccessAreaOption, ApiKey } from '@/types';

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const relativeFormat = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});

function relativeTo(iso: string): string {
    const minutes = Math.round((Date.parse(iso) - Date.now()) / 60000);

    if (Math.abs(minutes) < 60) {
        return relativeFormat.format(minutes, 'minute');
    }

    const hours = Math.round(minutes / 60);

    return Math.abs(hours) < 48
        ? relativeFormat.format(hours, 'hour')
        : relativeFormat.format(Math.round(hours / 24), 'day');
}

function expiryOf(apiKey: ApiKey): string {
    if (
        apiKey.expires_at === null ||
        new Date(apiKey.expires_at).getFullYear() >
            new Date().getFullYear() + 50
    ) {
        return 'Never expires';
    }

    const date = dateFormat.format(new Date(apiKey.expires_at));

    return apiKey.expired ? `Expired ${date}` : `Expires ${date}`;
}

export default function ApiKeyRow({
    apiKey,
    areas,
}: {
    apiKey: ApiKey;
    areas: AccessAreaOption[];
}) {
    const usage = apiKey.last_used_at
        ? `Last used ${relativeTo(apiKey.last_used_at)}`
        : 'Never used';

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3',
                apiKey.expired && 'opacity-50',
            )}
        >
            <div className="min-w-48 flex-1">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <KeyRound className="size-4 text-muted-foreground" />
                    {apiKey.name}
                </div>
                <div className="pl-6 text-xs text-muted-foreground">
                    {expiryOf(apiKey)} · {usage}
                </div>
            </div>
            <div className="flex items-center gap-2">
                {areas.map((area) => {
                    const Icon = areaIcons[area.value];
                    const level = apiKey.levels[area.value] ?? 'none';

                    return (
                        <Tooltip key={area.value}>
                            <TooltipTrigger asChild>
                                <Icon
                                    className={cn('size-4', levelColors[level])}
                                    aria-label={`${area.label}: ${level}`}
                                />
                            </TooltipTrigger>
                            <TooltipContent>
                                {area.label}:{' '}
                                {level === 'none' ? 'no access' : level}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </div>
            <Badge variant="outline" className="w-28 justify-center truncate">
                {apiKey.all_projects
                    ? 'All projects'
                    : apiKey.projects
                          .map((project) => project.name)
                          .join(', ') || 'No projects'}
            </Badge>
            <div className="w-20 text-right">
                {!apiKey.expired && (
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive"
                            >
                                Revoke
                            </Button>
                        }
                        title={`Revoke ${apiKey.name}?`}
                        description="Scripts using this key stop working at once. This can't be undone."
                        confirmLabel="Revoke"
                        destructive
                        onConfirm={() =>
                            runAction(ApiKeyController.destroy(apiKey.id), {
                                success: `Revoked ${apiKey.name}`,
                            })
                        }
                    />
                )}
            </div>
        </div>
    );
}
