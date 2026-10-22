import type { Page } from '@inertiajs/core';
import { toast } from 'sonner';

/**
 * Success toast for a write behind the `reauth` middleware: when the
 * server first asks for the password again, Inertia still "succeeds" by
 * landing on the confirm-password page — nothing was saved, so say nothing.
 */
export function successUnlessReauth(message: string) {
    return (page: Page) => {
        if (page.component !== 'auth/confirm-password') {
            toast.success(message);
        }
    };
}
