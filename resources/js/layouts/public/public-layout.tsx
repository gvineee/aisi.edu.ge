import { Link, usePage } from '@inertiajs/react';
import { type CSSProperties, type PropsWithChildren, useState } from 'react';
import { ArrowUpRight, Globe, Menu, X } from 'lucide-react';
import { login } from '@/routes';
import Logo from '@/components/public/logo';
import VisitRequestDialog from '@/components/public/visit-request-dialog';
import { VisitDialogContext } from '@/components/public/visit-dialog-context';
import { usePublicLocale } from '@/lib/public-i18n';
import type { Brand } from '@/types';

/**
 * Public site chrome (header, mobile menu, footer). Every visual value that
 * could differ per school — name, logo, colors, contact — comes from the
 * `brand` prop shared by HandleInertiaRequests, never hardcoded here (see
 * CLAUDE.md invariant #8 and docs/04-design-handoff.md section 6).
 */
export default function PublicLayout({ children }: PropsWithChildren) {
    const { brand } = usePage<{ brand: Brand | null }>().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const [visitOpen, setVisitOpen] = useState(false);
    const {
        t,
        switcher,
        nav: localeNav,
        homeUrl,
        isDefault,
    } = usePublicLocale();

    const name = brand?.name ?? '';
    const colors = brand?.colors ?? {};

    const brandVars = {
        '--brand-primary': colors.primary ?? '#132B45',
        '--brand-accent': colors.accent ?? '#F5683C',
        '--brand-secondary': colors.secondary ?? '#F4F7FA',
        '--brand-muted': colors.muted ?? '#526579',
    } as CSSProperties;

    // Another language has only its own translated pages (server-side list).
    const nav = localeNav ?? [
        { href: '/about', label: 'სკოლის შესახებ' },
        { href: '/learning', label: 'სწავლა' },
        { href: '/school-life', label: 'სასკოლო ცხოვრება' },
        { href: '/news', label: 'ამბები' },
        { href: '/library', label: 'ბიბლიოთეკა' },
        { href: '/contact', label: 'კონტაქტი' },
    ];

    const languages = switcher.length > 1 && (
        <nav
            aria-label={t('language')}
            className="flex items-center gap-1 text-xs"
        >
            <Globe
                size={14}
                aria-hidden
                className="mr-1 text-[var(--brand-muted)]"
            />
            {switcher.map((item) =>
                item.current ? (
                    <span
                        key={item.code}
                        aria-current="true"
                        lang={item.code}
                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-2 font-semibold"
                    >
                        {item.label}
                    </span>
                ) : (
                    <a
                        key={item.code}
                        href={item.url}
                        lang={item.code}
                        hrefLang={item.code}
                        title={item.sameContent ? undefined : t('untranslated')}
                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-2 text-[var(--brand-muted)] hover:bg-slate-100 hover:text-[var(--brand-primary)]"
                    >
                        {item.label}
                    </a>
                ),
            )}
        </nav>
    );

    const openVisitDialog = () => setVisitOpen(true);

    return (
        <VisitDialogContext.Provider value={openVisitDialog}>
            <div
                style={brandVars}
                className="min-h-screen overflow-x-hidden bg-white text-[var(--brand-primary)]"
            >
                <header className="relative overflow-x-hidden border-b border-slate-200 bg-white">
                    {languages && (
                        <div className="border-b border-slate-100">
                            <div className="mx-auto flex max-w-6xl justify-end px-6">
                                {languages}
                            </div>
                        </div>
                    )}
                    <div className="mx-auto flex h-[76px] max-w-6xl items-center gap-6 px-6">
                        <Link
                            href={homeUrl}
                            className="shrink-0"
                            aria-label={`${name} — ${t('home')}`}
                        >
                            <Logo brand={brand} />
                        </Link>

                        <nav className="hidden min-w-0 flex-1 [scrollbar-width:none] gap-3 overflow-x-auto lg:flex xl:gap-6 [&::-webkit-scrollbar]:hidden">
                            {nav.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className="public-navigation-link shrink-0 whitespace-nowrap"
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>

                        <div className="flex shrink-0 items-center gap-3">
                            <Link
                                href={login()}
                                className="hidden min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold whitespace-nowrap lg:inline-flex"
                            >
                                {t('myPortal', { name })}{' '}
                                <ArrowUpRight size={16} />
                            </Link>
                            <button
                                type="button"
                                aria-label={t('menu')}
                                aria-expanded={menuOpen}
                                className="flex h-11 w-11 items-center justify-center rounded-lg lg:hidden"
                                onClick={() => setMenuOpen((open) => !open)}
                            >
                                {menuOpen ? <X /> : <Menu />}
                            </button>
                        </div>
                    </div>

                    {menuOpen && (
                        <nav className="flex flex-col gap-1 border-t border-slate-200 bg-white p-4 lg:hidden">
                            {nav.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className="public-navigation-link public-navigation-link-mobile min-h-11 py-2"
                                    onClick={() => setMenuOpen(false)}
                                >
                                    {item.label}
                                </Link>
                            ))}
                            <Link
                                href={login()}
                                className="min-h-11 py-2 font-semibold"
                            >
                                {t('myPortal', { name })}
                            </Link>
                            {!isDefault && (
                                <a
                                    href="/"
                                    lang="ka"
                                    className="min-h-11 py-2 text-sm"
                                >
                                    {t('inOriginal')}
                                </a>
                            )}
                        </nav>
                    )}
                </header>

                <main id="main">{children}</main>

                <footer className="border-t border-slate-200 bg-[var(--brand-secondary)]">
                    <div className="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-10 text-sm text-[var(--brand-muted)] sm:flex-row sm:items-center sm:justify-between">
                        <Logo brand={brand} size="compact" />
                        <div className="flex flex-wrap gap-x-6 gap-y-2">
                            {brand?.contact.phone && (
                                <a
                                    href={`tel:${brand.contact.phone.replace(/\s+/g, '')}`}
                                    className="inline-flex min-h-11 items-center"
                                >
                                    {brand.contact.phone}
                                </a>
                            )}
                            {brand?.contact.email && (
                                <a
                                    href={`mailto:${brand.contact.email}`}
                                    className="inline-flex min-h-11 items-center"
                                >
                                    {brand.contact.email}
                                </a>
                            )}
                            {brand?.contact.address && (
                                <span>{brand.contact.address}</span>
                            )}
                        </div>
                    </div>
                    <div className="mx-auto max-w-6xl px-6">
                        <div className="flex flex-wrap gap-x-6 gap-y-0 border-t border-slate-200 py-2 text-xs text-[var(--brand-muted)]">
                            {isDefault ? (
                                <>
                                    <Link
                                        href="/admissions"
                                        className="inline-flex min-h-11 items-center hover:underline"
                                    >
                                        {t('admissions')}
                                    </Link>
                                    <Link
                                        href="/official-documents"
                                        className="inline-flex min-h-11 items-center hover:underline"
                                    >
                                        {t('documents')}
                                    </Link>
                                </>
                            ) : (
                                <a
                                    href="/"
                                    lang="ka"
                                    className="inline-flex min-h-11 items-center hover:underline"
                                >
                                    {t('inOriginal')}
                                </a>
                            )}
                            <a
                                href="https://aisi.edupage.org"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-11 items-center hover:underline"
                            >
                                {t('journal')}
                            </a>
                        </div>
                    </div>
                    <div className="mx-auto max-w-6xl px-6">
                        <p className="border-t border-slate-200 py-4 text-xs text-[var(--brand-muted)]">
                            {t('madeByBefore')}
                            <a
                                href="https://syslab.ge"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-11 items-center font-semibold underline-offset-2 hover:underline"
                            >
                                syslab.ge
                            </a>
                            {t('madeByAfter')}
                        </p>
                    </div>
                </footer>

                <VisitRequestDialog
                    open={visitOpen}
                    onOpenChange={setVisitOpen}
                />
            </div>
        </VisitDialogContext.Provider>
    );
}
