import { createContext, useContext } from 'react';

/**
 * Lets any public page (not just the header) open the shared visit-request
 * dialog that PublicLayout owns, e.g. a page's own contact-section CTA.
 */
export const VisitDialogContext = createContext<() => void>(() => {});

export function useOpenVisitDialog(): () => void {
    return useContext(VisitDialogContext);
}
