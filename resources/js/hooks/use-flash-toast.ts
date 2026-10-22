import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Shows the server's toast for a visit. The server's message is the
 * authoritative outcome (it only exists if the action actually ran, and
 * may carry counts or an error), so a toast the page itself raised in the
 * same visit's onSuccess is replaced rather than shown twice. Inertia fires
 * `flash` synchronously before onSuccess, hence the deferred check.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            const before = new Set(toast.getToasts().map((t) => t.id));

            setTimeout(() => {
                toast
                    .getToasts()
                    .filter((t) => !before.has(t.id))
                    .forEach((t) => toast.dismiss(t.id));
                toast[data.type](data.message);
            }, 0);
        });
    }, []);
}
