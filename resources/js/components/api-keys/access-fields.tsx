import { useState } from 'react';
import { areaIcons, levelColors } from '@/components/api-keys/area-icons';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import type { AccessLevel, AccessAreaOption } from '@/types';

function levelsFor(
    areas: AccessAreaOption[],
    level: AccessLevel,
): Record<string, AccessLevel> {
    return Object.fromEntries(
        areas.map((area) => [
            area.value,
            area.read_only && level === 'write' ? 'read' : level,
        ]),
    );
}

export default function AccessFields({
    areas,
    projects,
    errors,
}: {
    areas: AccessAreaOption[];
    projects: { id: string; name: string }[];
    errors: { permissions?: string; projects?: string };
}) {
    const [levels, setLevels] = useState(() => levelsFor(areas, 'none'));
    const [allProjects, setAllProjects] = useState(true);
    const [selected, setSelected] = useState<string[]>([]);

    return (
        <>
            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label>Permissions</Label>
                    <div className="flex gap-1">
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="h-auto px-1 text-xs"
                            onClick={() => setLevels(levelsFor(areas, 'read'))}
                        >
                            Read everything
                        </Button>
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="h-auto px-1 text-xs"
                            onClick={() => setLevels(levelsFor(areas, 'write'))}
                        >
                            Full access
                        </Button>
                    </div>
                </div>
                <div className="divide-y rounded-lg border">
                    {areas.map((area) => {
                        const Icon = areaIcons[area.value];
                        const level = levels[area.value];

                        return (
                            <div
                                key={area.value}
                                className="flex items-center gap-3 px-3 py-2"
                            >
                                <input
                                    type="hidden"
                                    name={`permissions[${area.value}]`}
                                    value={level}
                                />
                                <Icon
                                    className={cn('size-4', levelColors[level])}
                                />
                                <span className="flex-1 text-sm">
                                    {area.label}
                                </span>
                                <ToggleGroup
                                    type="single"
                                    variant="outline"
                                    size="sm"
                                    value={level}
                                    onValueChange={(value) =>
                                        value &&
                                        setLevels({
                                            ...levels,
                                            [area.value]: value as AccessLevel,
                                        })
                                    }
                                >
                                    <ToggleGroupItem
                                        value="none"
                                        className="px-2.5 text-xs"
                                    >
                                        None
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="read"
                                        className="px-2.5 text-xs"
                                    >
                                        Read
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="write"
                                        className="px-2.5 text-xs"
                                        disabled={area.read_only}
                                    >
                                        Write
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>
                        );
                    })}
                </div>
                <InputError message={errors.permissions} />
            </div>

            <div className="space-y-2">
                <Label>Projects</Label>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={allProjects ? 'all' : 'selected'}
                    onValueChange={(value) =>
                        value && setAllProjects(value === 'all')
                    }
                >
                    <ToggleGroupItem value="all" className="flex-1">
                        All projects
                    </ToggleGroupItem>
                    <ToggleGroupItem value="selected" className="flex-1">
                        Selected
                    </ToggleGroupItem>
                </ToggleGroup>
                {allProjects ? (
                    <input type="hidden" name="projects" value="all" />
                ) : (
                    <div className="flex flex-wrap gap-4 pt-2">
                        {projects.map((project) => (
                            <label
                                key={project.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    name="projects[]"
                                    value={project.id}
                                    checked={selected.includes(project.id)}
                                    onCheckedChange={(checked) =>
                                        setSelected(
                                            checked
                                                ? [...selected, project.id]
                                                : selected.filter(
                                                      (id) => id !== project.id,
                                                  ),
                                        )
                                    }
                                />
                                {project.name}
                            </label>
                        ))}
                    </div>
                )}
                <InputError message={errors.projects} />
            </div>
        </>
    );
}
