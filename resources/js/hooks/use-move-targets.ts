import { usePage } from '@inertiajs/react';
import type { Project } from '@/types';

export function useMoveTargets(): Project[] {
    const { currentProject, projects } = usePage().props;

    return projects.filter((project) => project.id !== currentProject?.id);
}
