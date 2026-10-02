/**
 * Display helpers shared by the gradebook, assessment settings and
 * progress pages. Scores arrive from the server as fixed two-decimal
 * strings ("7.50"); they are only ever formatted here, never recomputed.
 */
export function formatScore(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(Number(value));
}

export function formatPercent(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${Number(value).toLocaleString('ka-GE', { maximumFractionDigits: 1 })}%`;
}

export function formatDay(isoDate: string): string {
    return new Date(`${isoDate}T12:00:00Z`).toLocaleDateString('ka-GE', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Tbilisi',
    });
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('ka-GE', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Tbilisi',
    });
}

export function firstError(errors: Record<string, string>): string | undefined {
    return Object.values(errors)[0];
}
