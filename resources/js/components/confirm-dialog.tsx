import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';

export default function ConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel,
    destructive = false,
    typeToConfirm,
    children,
    open,
    onOpenChange,
    onConfirm,
}: {
    trigger?: ReactNode;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    typeToConfirm?: string;
    children?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onConfirm: () => void;
}) {
    const [typed, setTyped] = useState('');
    const blocked = typeToConfirm !== undefined && typed !== typeToConfirm;

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                setTyped('');
                onOpenChange?.(next);
            }}
        >
            {trigger && (
                <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
            )}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-2">{description}</div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {children}
                {typeToConfirm !== undefined && (
                    <div className="space-y-2 text-sm">
                        <p>
                            Type <strong>{typeToConfirm}</strong> to confirm.
                        </p>
                        <Input
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            autoComplete="off"
                        />
                    </div>
                )}
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={blocked}
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
