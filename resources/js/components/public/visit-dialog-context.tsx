import { createContext, useContext } from 'react';

/**
 * Lets any public page (not just the header) open the shared visit-request
 * dialog that PublicLayout owns, e.g. a page's own contact-section CTA.
 *
 * No default opener: a button rendered outside PublicLayout used to get a
 * silent no-op and nobody noticed. Now that mistake fails loudly.
 */
export const VisitDialogContext = createContext<(() => void) | null>(null);

export function useOpenVisitDialog(): () => void {
    const open = useContext(VisitDialogContext);

    if (open === null) {
        throw new Error(
            'useOpenVisitDialog must be used inside PublicLayout (it owns the visit dialog).',
        );
    }

    return open;
}
