import { formatGeorgian } from '@/lib/ka-dates';

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

// Georgian even where the browser has no ka-GE data (lib/ka-dates).
const TBILISI = 'Asia/Tbilisi';

export function formatDay(isoDate: string): string {
    return formatGeorgian(new Date(`${isoDate}T12:00:00Z`), {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: TBILISI,
    });
}

export function formatDateTime(iso: string): string {
    return formatGeorgian(new Date(iso), {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: TBILISI,
    });
}

/** "ოქტომბერი 2026" for "2026-10". */
export function formatMonth(yearMonth: string): string {
    return formatGeorgian(new Date(`${yearMonth}-15T12:00:00Z`), {
        month: 'long',
        year: 'numeric',
        timeZone: TBILISI,
    });
}

/** "ხუთ, 5 ნოემბერი" for a moment, on the given clock. */
export function formatWeekdayDay(iso: string, timeZone = TBILISI): string {
    return formatGeorgian(new Date(iso), {
        weekday: 'short',
        day: 'numeric',
        month: 'long',
        timeZone,
    });
}

/** "09:05" for a moment, on the given clock. */
export function formatTime(iso: string, timeZone = TBILISI): string {
    return formatGeorgian(
        new Date(iso),
        { hour: '2-digit', minute: '2-digit', timeZone },
        'time',
    );
}

export function firstError(errors: Record<string, string>): string | undefined {
    return Object.values(errors)[0];
}
