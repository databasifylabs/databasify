import { useEffect, useState } from 'react';
import { durationOf } from '@/lib/format';
import type { ServerLog } from '@/types';

const tickMilliseconds = 1000;

export default function LiveDuration({
    log,
    prefix = '',
    fallback = null,
}: {
    log: ServerLog;
    prefix?: string;
    fallback?: string | null;
}) {
    const [, setTick] = useState(0);
    const running = log.status === 'running';

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = setInterval(
            () => setTick((tick) => tick + 1),
            tickMilliseconds,
        );

        return () => clearInterval(timer);
    }, [running]);

    const duration = durationOf(
        log.created_at,
        running ? null : log.updated_at,
    );

    return duration === null ? fallback : `${prefix}${duration}`;
}
