import { Form, usePage } from '@inertiajs/react';
import { type CSSProperties, type PropsWithChildren } from 'react';
import { LogOut } from 'lucide-react';
import { logout } from '@/routes';
import { Button } from '@/components/ui/button';
import Logo from '@/components/public/logo';
import type { Brand } from '@/types';

type Auth = { user: { name: string; email: string } | null };

/**
 * Authenticated portal chrome ("ჩემი {school}"). Deliberately simple for
 * now — the full sidebar/bottom-nav from docs/04's PortalLayout mapping
 * is a follow-up once schedule/library/payments features exist to put in
 * it; this is the real, working "today" screen shell in the meantime.
 */
export default function PortalLayout({ children }: PropsWithChildren) {
    const { brand, auth } = usePage<{ brand: Brand | null; auth: Auth }>()
        .props;

    const colors = brand?.colors ?? {};
    const brandVars = {
        '--brand-primary': colors.primary ?? '#132B45',
        '--brand-accent': colors.accent ?? '#F5683C',
        '--brand-secondary': colors.secondary ?? '#F4F7FA',
    } as CSSProperties;

    return (
        <div
            style={brandVars}
            className="min-h-screen bg-[var(--brand-secondary)] text-[var(--brand-primary)]"
        >
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex h-[76px] max-w-5xl items-center justify-between gap-6 px-6">
                    <div className="flex items-center gap-3">
                        <Logo brand={brand} size="compact" />
                        <span className="hidden text-sm text-slate-400 sm:inline">
                            ჩემი {brand?.name}
                        </span>
                    </div>
                    <div className="flex items-center gap-4">
                        {auth.user && (
                            <span className="text-sm text-slate-600">
                                {auth.user.name}
                            </span>
                        )}
                        <Form {...logout.form()}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <LogOut size={16} /> გასვლა
                                </Button>
                            )}
                        </Form>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-6 py-10">{children}</main>
        </div>
    );
}
