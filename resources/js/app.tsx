import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { trackProjectUrlDefaults } from '@/lib/project-url-defaults';
import AppLayout from '@/layouts/app/app-sidebar-layout';
import AuthLayout from '@/layouts/auth/auth-simple-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { configureEcho } from '@laravel/echo-react';

const env = import.meta.env;
const scheme = env.VITE_REVERB_SCHEME ?? window.location.protocol.slice(0, -1);
const port = Number(
    env.VITE_REVERB_PORT ??
        (window.location.port || (scheme === 'https' ? 443 : 80)),
);

configureEcho({
    broadcaster: 'reverb',
    key:
        env.VITE_REVERB_APP_KEY ??
        document.querySelector<HTMLMetaElement>('meta[name="reverb-key"]')
            ?.content,
    wsHost: env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});

const appName = import.meta.env.VITE_APP_NAME || 'Databasify';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page, ssr }) {
        trackProjectUrlDefaults(page, ssr);

        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
