const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000],
    ['month', 2592000],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
];

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

export function timeAgo(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const unit = units.find(([, size]) => Math.abs(seconds) >= size);

    return unit
        ? relative.format(Math.round(seconds / unit[1]), unit[0])
        : 'just now';
}

export function dayLabel(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const days = Math.round(
        (startOfDay(new Date()) - startOfDay(new Date(iso))) / 86400000,
    );

    if (days < 2) {
        return capitalize(relative.format(-days, 'day'));
    }

    return new Date(iso).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

function startOfDay(date: Date): number {
    return new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    ).getTime();
}

export function formatMemory(megabytes: number | null): string {
    if (megabytes === null) {
        return '—';
    }

    return megabytes >= 1024
        ? `${Math.ceil(megabytes / 1024)} GB`
        : `${megabytes} MB`;
}

export function formatUsableMemory(megabytes: number): string {
    return `${(megabytes / 1024).toFixed(1)} GB usable`;
}

export function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export const hoursOfDay = Array.from({ length: 24 }, (_, hour) => hour);

export function hourLabel(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

export function pluralize(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

export function durationOf(
    startedAt: string | null,
    finishedAt: string | null,
): string | null {
    if (!startedAt) {
        return null;
    }

    const end = finishedAt ? new Date(finishedAt) : new Date();
    const seconds = Math.max(
        0,
        Math.round((end.getTime() - new Date(startedAt).getTime()) / 1000),
    );

    return seconds >= 60
        ? `${Math.floor(seconds / 60)}m ${seconds % 60}s`
        : `${seconds}s`;
}
