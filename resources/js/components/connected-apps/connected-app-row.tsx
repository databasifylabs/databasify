import ConnectedAppController from '@/actions/App/Http/Controllers/ConnectedApps/ConnectedAppController';
import { areaIcons, levelColors } from '@/components/api-keys/area-icons';
import BrandIcon from '@/components/brand-icon';
import ConfirmDialog from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dayLabel, timeAgo } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import { cn } from '@/lib/utils';
import type { AccessAreaOption, ConnectedApp } from '@/types';

export default function ConnectedAppRow({
    app,
    areas,
}: {
    app: ConnectedApp;
    areas: AccessAreaOption[];
}) {
    const usage = app.last_used_at
        ? `Used ${timeAgo(app.last_used_at)}`
        : 'Never used';

    return (
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <div className="flex min-w-48 flex-1 items-center gap-3">
                <BrandIcon group="ai-agents" src={app.icon} size="sm" />
                <div className="min-w-0">
                    <div className="truncate text-sm font-medium">
                        {app.name}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {app.redirect_host} · Connected{' '}
                        {dayLabel(app.connected_at)} · {usage}
                    </div>
                </div>
            </div>
            <div className="flex items-center gap-2">
                {areas.map((area) => {
                    const Icon = areaIcons[area.value];
                    const level = app.levels[area.value] ?? 'none';

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
                {app.all_projects
                    ? 'All projects'
                    : app.projects.map((project) => project.name).join(', ') ||
                      'No projects'}
            </Badge>
            <ConfirmDialog
                trigger={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                    >
                        Remove
                    </Button>
                }
                title={`Remove ${app.name}?`}
                description="It loses access at once. Connecting it again asks you to approve it again."
                confirmLabel="Remove"
                destructive
                onConfirm={() =>
                    runAction(ConnectedAppController.destroy(app.id), {
                        success: `Removed ${app.name}`,
                    })
                }
            />
        </div>
    );
}
