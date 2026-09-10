import { Link, usePage } from '@inertiajs/react';
import { type CSSProperties, type PropsWithChildren, useState } from 'react';
import { Menu, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import VisitRequestDialog from '@/components/public/visit-request-dialog';
import { VisitDialogContext } from '@/components/public/visit-dialog-context';
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

    const name = brand?.name ?? '';
    const colors = brand?.colors ?? {};

    const brandVars = {
        '--brand-primary': colors.primary ?? '#132B45',
        '--brand-accent': colors.accent ?? '#F5683C',
        '--brand-secondary': colors.secondary ?? '#F4F7FA',
        '--brand-muted': colors.muted ?? '#526579',
    } as CSSProperties;

    const nav = [
        { href: '#about', label: 'სკოლის შესახებ' },
        { href: '#learning', label: 'სწავლა' },
        { href: '#life', label: 'სასკოლო ცხოვრება' },
        { href: '#contact', label: 'კონტაქტი' },
    ];

    const openVisitDialog = () => setVisitOpen(true);

    return (
        <VisitDialogContext.Provider value={openVisitDialog}>
            <div
                style={brandVars}
                className="min-h-screen bg-white text-[var(--brand-primary)]"
            >
                <header className="relative border-b border-slate-200 bg-white">
                    <div className="mx-auto flex h-[76px] max-w-6xl items-center justify-between gap-6 px-6">
                        <Link
                            href="/"
                            className="flex items-center gap-3"
                            aria-label={`${name} — მთავარი`}
                        >
                            {brand?.logoUrl && (
                                <img
                                    src={brand.logoUrl}
                                    alt=""
                                    width={48}
                                    height={48}
                                    className="h-12 w-12 object-contain"
                                />
                            )}
                            <span className="text-2xl font-extrabold tracking-tight">
                                {name}
                            </span>
                        </Link>

                        <nav className="hidden gap-8 text-sm font-medium md:flex">
                            {nav.map((item) => (
                                <a key={item.href} href={item.href}>
                                    {item.label}
                                </a>
                            ))}
                        </nav>

                        <div className="flex items-center gap-3">
                            <Button
                                className="hidden bg-[var(--brand-accent)] text-[var(--brand-primary)] hover:brightness-95 sm:inline-flex"
                                onClick={() => setVisitOpen(true)}
                            >
                                დაგეგმე ვიზიტი
                            </Button>
                            <button
                                type="button"
                                aria-label="მენიუ"
                                aria-expanded={menuOpen}
                                className="flex h-11 w-11 items-center justify-center rounded-lg md:hidden"
                                onClick={() => setMenuOpen((open) => !open)}
                            >
                                {menuOpen ? <X /> : <Menu />}
                            </button>
                        </div>
                    </div>

                    {menuOpen && (
                        <nav className="flex flex-col gap-1 border-t border-slate-200 bg-white p-4 text-sm font-medium md:hidden">
                            {nav.map((item) => (
                                <a
                                    key={item.href}
                                    href={item.href}
                                    className="min-h-11 py-2"
                                    onClick={() => setMenuOpen(false)}
                                >
                                    {item.label}
                                </a>
                            ))}
                            <Button
                                className="mt-2 bg-[var(--brand-accent)] text-[var(--brand-primary)]"
                                onClick={() => {
                                    setMenuOpen(false);
                                    setVisitOpen(true);
                                }}
                            >
                                დაგეგმე ვიზიტი
                            </Button>
                        </nav>
                    )}
                </header>

                <main id="main">{children}</main>

                <footer className="border-t border-slate-200 bg-[var(--brand-secondary)]">
                    <div className="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-10 text-sm text-[var(--brand-muted)] sm:flex-row sm:items-center sm:justify-between">
                        <span className="text-base font-semibold text-[var(--brand-primary)]">
                            {name}
                        </span>
                        <div className="flex flex-wrap gap-x-6 gap-y-2">
                            {brand?.contact.phone && (
                                <a
                                    href={`tel:${brand.contact.phone.replace(/\s+/g, '')}`}
                                >
                                    {brand.contact.phone}
                                </a>
                            )}
                            {brand?.contact.email && (
                                <a href={`mailto:${brand.contact.email}`}>
                                    {brand.contact.email}
                                </a>
                            )}
                            {brand?.contact.address && (
                                <span>{brand.contact.address}</span>
                            )}
                        </div>
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
