import { usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import PlatformLayout from '@/layouts/platform-layout';
import PortalLayout from '@/layouts/portal/portal-layout';

/**
 * The account pages (profile, security) open in the chrome the person
 * came from: the platform console for an operator without a school role,
 * otherwise the school's portal — never the starter kit's own shell.
 */
export default function AccountLayout({ children }: PropsWithChildren) {
    const { platformOperator, portal } = usePage<{
        platformOperator?: boolean;
        portal?: { activeRole: string | null } | null;
    }>().props;

    if (platformOperator && !portal?.activeRole) {
        return <PlatformLayout>{children}</PlatformLayout>;
    }

    return <PortalLayout>{children}</PortalLayout>;
}
