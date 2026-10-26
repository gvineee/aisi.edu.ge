import { Form, Link, router, usePage } from '@inertiajs/react';
import { type CSSProperties, type PropsWithChildren } from 'react';
import {
    Activity,
    BookOpen,
    LibraryBig,
    ListChecks,
    CalendarCheck,
    CalendarClock,
    CalendarPlus,
    CalendarRange,
    ChartLine,
    ClipboardList,
    FileCheck2,
    MailCheck,
    FileText,
    GraduationCap,
    HeartPulse,
    House,
    LayoutGrid,
    LifeBuoy,
    LogOut,
    Menu,
    MessageSquareText,
    Newspaper,
    PenLine,
    Presentation,
    IdCard,
    Timer,
    Repeat,
    Scale,
    ScrollText,
    UserCheck,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-react';
import { logout } from '@/routes';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
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
    assignments: ClipboardList,
    'academic-structure': CalendarRange,
    substitutions: Repeat,
    timetable: CalendarClock,
    'admissions-pipeline': UserPlus,
    'school-visits': CalendarPlus,
    books: BookOpen,
    'book-lists': ListChecks,
    'library-desk': LibraryBig,
    pickup: UserCheck,
    consents: FileCheck2,
    'absence-notes': MailCheck,
    gradebook: PenLine,
    'assessment-settings': Scale,
    progress: ChartLine,
    'report-cards': ScrollText,
    health: HeartPulse,
    appointments: CalendarCheck,
    services: LayoutGrid,
    pulse: Activity,
    finance: Wallet,
    'support-access': LifeBuoy,
    'teacher-workspace': Presentation,
    staff: IdCard,
    'worked-hours': Timer,
    students: GraduationCap,
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
    const { url } = usePage();
    const currentPath = url.split('?')[0];
    const isActive = (href: string) => {
        const path = new URL(href, 'http://x').pathname;
        return (
            currentPath === path ||
            (path !== '/dashboard' && currentPath.startsWith(`${path}/`))
        );
    };
    // Consecutive items of one section (the server sends them in order).
    const sections = navItems.reduce<
        { key: string; label: string | null; items: typeof navItems }[]
    >((all, item) => {
        const last = all[all.length - 1];
        if (last && last.key === item.group) {
            last.items.push(item);
        } else {
            all.push({
                key: item.group,
                label: item.groupLabel,
                items: [item],
            });
        }
        return all;
    }, []);
    const navLink = (item: (typeof navItems)[number], className: string) => {
        const Icon = NAV_ICONS[item.icon] ?? House;
        const active = isActive(item.href);
        return (
            <Link
                key={item.key}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={`${className} ${active ? 'bg-slate-100 font-semibold' : ''}`}
            >
                <Icon size={18} aria-hidden />
                {item.label}
            </Link>
        );
    };
    // docs/04 §5: the mobile bottom bar holds at most 5 entries. Past that,
    // the first 4 stay pinned and the rest move into a "მეტი" sheet so
    // nothing overflows at 360px.
    const MOBILE_NAV_LIMIT = 5;
    const overflowsMobileNav = navItems.length > MOBILE_NAV_LIMIT;
    const mobilePinned = overflowsMobileNav
        ? navItems.slice(0, MOBILE_NAV_LIMIT - 1)
        : navItems;
    const mobileOverflow = overflowsMobileNav
        ? navItems.slice(MOBILE_NAV_LIMIT - 1)
        : [];
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
                    <SidebarContent className="gap-1 overflow-y-auto p-3">
                        <nav
                            aria-label="პორტალის ნავიგაცია"
                            className="flex flex-col gap-3"
                        >
                            {sections.map((section) =>
                                section.label === null ? (
                                    <div
                                        key={section.key}
                                        className="flex flex-col gap-0.5"
                                    >
                                        {section.items.map((item) =>
                                            navLink(
                                                item,
                                                'flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-[var(--brand-primary)] hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none',
                                            ),
                                        )}
                                    </div>
                                ) : (
                                    <details
                                        key={section.key}
                                        open={
                                            section.items.some((item) =>
                                                isActive(item.href),
                                            ) ||
                                            section.items.length <= 3 ||
                                            undefined
                                        }
                                        className="group"
                                    >
                                        <summary className="flex min-h-9 cursor-pointer list-none items-center justify-between rounded-md px-3 text-xs font-semibold tracking-wide text-slate-500 uppercase hover:text-slate-700 [&::-webkit-details-marker]:hidden">
                                            {section.label}
                                            <span
                                                aria-hidden
                                                className="transition group-open:rotate-90"
                                            >
                                                ›
                                            </span>
                                        </summary>
                                        <div className="mt-0.5 flex flex-col gap-0.5">
                                            {section.items.map((item) =>
                                                navLink(
                                                    item,
                                                    'flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-[var(--brand-primary)] hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none',
                                                ),
                                            )}
                                        </div>
                                    </details>
                                ),
                            )}
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
                    {mobilePinned.map((item) => {
                        const Icon = NAV_ICONS[item.icon];

                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                className="flex min-h-11 min-w-0 flex-1 flex-col items-center justify-center gap-1 px-1 py-2 text-center text-xs font-medium text-[var(--brand-primary)] focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                            >
                                <Icon size={21} />
                                <span className="w-full truncate">
                                    {item.label}
                                </span>
                            </Link>
                        );
                    })}
                    {mobileOverflow.length > 0 && (
                        <Sheet>
                            <SheetTrigger asChild>
                                <button
                                    type="button"
                                    className="flex min-h-11 min-w-0 flex-1 flex-col items-center justify-center gap-1 px-1 py-2 text-xs font-medium text-[var(--brand-primary)] focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                                >
                                    <Menu size={21} />
                                    <span>მეტი</span>
                                </button>
                            </SheetTrigger>
                            <SheetContent
                                side="bottom"
                                className="max-h-[80vh] overflow-y-auto"
                                style={{
                                    paddingBottom:
                                        'env(safe-area-inset-bottom)',
                                }}
                            >
                                <SheetTitle className="px-4 pt-4">
                                    სხვა განყოფილებები
                                </SheetTitle>
                                <div className="grid gap-3 p-2">
                                    {sections.map((section) => {
                                        const items = section.items.filter(
                                            (item) =>
                                                mobileOverflow.includes(item),
                                        );
                                        if (items.length === 0) return null;
                                        return (
                                            <section key={section.key}>
                                                {section.label && (
                                                    <h3 className="px-3 pb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                                        {section.label}
                                                    </h3>
                                                )}
                                                <ul className="grid gap-0.5">
                                                    {items.map((item) => (
                                                        <li key={item.key}>
                                                            {navLink(
                                                                item,
                                                                'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-[var(--brand-primary)] hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none',
                                                            )}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </section>
                                        );
                                    })}
                                </div>
                            </SheetContent>
                        </Sheet>
                    )}
                </nav>
            )}
        </div>
    );
}
