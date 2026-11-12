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
            version: string;
            auth: Auth;
            sidebarOpen: boolean;
            horizonUrl: string | null;
            currentProject: Project | null;
            projects: Project[];
            updateAvailable: boolean;
            [key: string]: unknown;
        };
    }
}
