import { router } from '@inertiajs/react';
import type { RequestPayload, VisitOptions } from '@inertiajs/core';
import { toast } from 'sonner';

type Route = {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

export function runAction(
    route: Route,
    {
        data,
        success,
        onSuccess,
        onFinish,
    }: {
        data?: RequestPayload;
        success?: string;
        onSuccess?: () => void;
        onFinish?: () => void;
    } = {},
): void {
    const options: VisitOptions = {
        preserveScroll: true,
        preserveState: 'errors',
        onError: (errors) => {
            toast.error(Object.values(errors)[0] ?? 'Something went wrong');
        },
        onSuccess: () => {
            if (success) {
                toast.success(success);
            }

            onSuccess?.();
        },
        onFinish: () => onFinish?.(),
    };

    router.visit(route.url, { ...options, method: route.method, data });
}
