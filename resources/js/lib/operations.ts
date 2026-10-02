import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { firstError } from '@/lib/assessment';

export const WEEKDAYS: Record<number, string> = {
    1: 'ორშაბათი',
    2: 'სამშაბათი',
    3: 'ოთხშაბათი',
    4: 'ხუთშაბათი',
    5: 'პარასკევი',
    6: 'შაბათი',
    7: 'კვირა',
};

/** GEL from integer tetri, formatted for ka-GE. */
export function formatMoney(minor: number | null, currency = 'GEL'): string {
    if (minor === null) return 'უფასო';

    return new Intl.NumberFormat('ka-GE', {
        style: 'currency',
        currency,
    }).format(minor / 100);
}

export function postWithToast(
    url: string,
    data: Record<string, unknown> | FormData,
    success: string,
    onDone?: () => void,
) {
    router.post(url, data as Record<string, string>, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(success);
            onDone?.();
        },
        onError: (errors) => toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
    });
}
