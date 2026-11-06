import type { ReactNode } from 'react';

export type DialogControl = {
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};
