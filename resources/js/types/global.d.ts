import type { Auth } from '@/types/auth';
import type { Project } from '@/types/projects';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            clusterMemoryMb: number;
            horizonUrl: string | null;
            currentProject: Project | null;
            projects: Project[];
            [key: string]: unknown;
        };
    }
}
