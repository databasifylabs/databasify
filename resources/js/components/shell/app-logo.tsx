import AppLogoIcon from '@/components/shell/app-logo-icon';
import AppWordmark from '@/components/shell/app-wordmark';

export default function AppLogo() {
    return (
        <>
            <span className="hidden aspect-square size-8 shrink-0 items-center justify-center group-data-[collapsible=icon]:flex">
                <AppLogoIcon className="size-8" />
            </span>
            <span className="flex items-center px-1 group-data-[collapsible=icon]:hidden">
                <AppWordmark className="h-6 w-auto text-foreground" />
            </span>
        </>
    );
}
