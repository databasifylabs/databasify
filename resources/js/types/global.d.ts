import type { CreatedApiKeyFlash } from '@/types/api-keys';
import type { Auth } from '@/types/auth';
import type { Project } from '@/types/projects';
import type { FlashToast } from '@/types/ui';

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
            updatesEnabled: boolean;
            updateAvailable: boolean;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            apiKey?: CreatedApiKeyFlash;
        };
    }
}
