import { Form, Link, router, usePage } from '@inertiajs/react';
import { type CSSProperties, type PropsWithChildren } from 'react';
import {
    FileText,
    GraduationCap,
    House,
    LogOut,
    MessageSquareText,
    Newspaper,
    Users,
} from 'lucide-react';
import { logout } from '@/routes';
import { Button } from '@/components/ui/button';
import {
    Sidebar,
    SidebarContent,
    SidebarProvider,
} from '@/components/ui/sidebar';
import Logo from '@/components/public/logo';
import type { Brand, PortalContextProps, PortalNavIconKey } from '@/types';

type Auth = { user: { name: string; email: string } | null };

const NAV_ICONS: Record<PortalNavIconKey, typeof House> = {
    home: House,
    documents: FileText,
    messages: MessageSquareText,
    cms: Newspaper,
    members: Users,
    teachers: GraduationCap,
};

/**
 * Authenticated portal chrome — real sidebar on desktop (≥768px, matching
 * the shadcn sidebar's own `useIsMobile` breakpoint), fixed bottom
 * navigation on mobile (docs/04 §5: portal sidebar becomes bottom-nav below
 * the desktop breakpoint; max 5 items; safe-area aware). Every nav item and
 * the current/available roles come from the server (`portal` shared prop,
 * see PortalContext) — nothing here decides what a user is allowed to see;
 * hiding a link is not a security boundary, every route re-checks itself.
 */
export default function PortalLayout({ children }: PropsWithChildren) {
    const { brand, auth, portal } = usePage<{
        brand: Brand | null;
        auth: Auth;
        portal: PortalContextProps | null;
    }>().props;

    const colors = brand?.colors ?? {};
    const brandVars = {
        '--brand-primary': colors.primary ?? '#132B45',
        '--brand-accent': colors.accent ?? '#F5683C',
        '--brand-secondary': colors.secondary ?? '#F4F7FA',
    } as CSSProperties;

    const navItems = portal?.navItems ?? [];
    const availableRoles = portal?.availableRoles ?? [];
    const showRoleSwitcher = availableRoles.length > 1;

    const switchRole = (role: string) => {
        router.post('/portal/active-role', { role }, { preserveScroll: true });
    };

    const RoleSwitcher = ({ className = '' }: { className?: string }) => (
        <label className={`flex items-center gap-2 text-sm ${className}`}>
            <span className="sr-only">პორტალის როლი</span>
            <select
                value={portal?.activeRole ?? ''}
                onChange={(event) => switchRole(event.target.value)}
                className="h-9 min-w-0 rounded-md border border-slate-300 bg-white px-2 text-sm"
                aria-label="სივრცის დათვალიერება — აირჩიეთ როლი"
            >
                {availableRoles.map((role) => (
                    <option key={role.value} value={role.value}>
                        {role.label}
                    </option>
                ))}
            </select>
        </label>
    );

    return (
        <div
            style={brandVars}
            className="min-h-screen bg-[var(--brand-secondary)] text-[var(--brand-primary)]"
        >
            <a
                href="#portal-main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:shadow-lg"
            >
                მთავარ შინაარსზე გადასვლა
            </a>

            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex h-[76px] max-w-6xl items-center justify-between gap-4 px-6">
                    <div className="flex items-center gap-3">
                        <Logo brand={brand} size="compact" />
                        <span className="hidden text-sm text-slate-400 sm:inline">
                            ჩემი {brand?.name}
                        </span>
                    </div>
                    <div className="flex items-center gap-3">
                        {showRoleSwitcher && (
                            <RoleSwitcher className="hidden sm:flex" />
                        )}
                        {auth.user && (
                            <span className="hidden text-sm text-slate-600 sm:inline">
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
                                    className="focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:ring-offset-2"
                                >
                                    <LogOut size={16} /> გასვლა
                                </Button>
                            )}
                        </Form>
                    </div>
                </div>
                {showRoleSwitcher && (
                    <div className="border-t border-slate-100 px-6 py-2 sm:hidden">
                        <RoleSwitcher />
                    </div>
                )}
            </header>

            <SidebarProvider className="mx-auto max-w-6xl items-start bg-transparent">
                <Sidebar
                    collapsible="none"
                    className="sticky top-0 hidden h-[calc(100vh-76px)] w-56 shrink-0 border-r border-slate-200 bg-white md:flex"
                >
                    <SidebarContent className="gap-1 p-3">
                        <nav
                            aria-label="პორტალის ნავიგაცია"
                            className="flex flex-col gap-1"
                        >
                            {navItems.map((item) => {
                                const Icon = NAV_ICONS[item.icon];

                                return (
                                    <Link
                                        key={item.key}
                                        href={item.href}
                                        className="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-[var(--brand-primary)] hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                                    >
                                        <Icon size={20} />
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </nav>
                    </SidebarContent>
                </Sidebar>

                <main
                    id="portal-main"
                    className="min-w-0 flex-1 px-6 py-10 pb-24 md:pb-10"
                >
                    {children}
                </main>
            </SidebarProvider>

            {navItems.length > 0 && (
                <nav
                    aria-label="მობილური პორტალის ნავიგაცია"
                    className="fixed inset-x-0 bottom-0 z-40 flex border-t border-slate-200 bg-white md:hidden"
                    style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
                >
                    {navItems.map((item) => {
                        const Icon = NAV_ICONS[item.icon];

                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                className="flex min-h-11 flex-1 flex-col items-center justify-center gap-1 py-2 text-xs font-medium text-[var(--brand-primary)] focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                            >
                                <Icon size={21} />
                                <span>{item.label}</span>
                            </Link>
                        );
                    })}
                </nav>
            )}
        </div>
    );
}
