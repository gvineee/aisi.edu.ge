import { Link, router, usePage } from '@inertiajs/react';
import {
    type CSSProperties,
    type KeyboardEvent as ReactKeyboardEvent,
    type PropsWithChildren,
    useEffect,
    useRef,
    useState,
} from 'react';
import { ArrowUpRight, ChevronRight, Menu, X } from 'lucide-react';
import { login } from '@/routes';
import Logo from '@/components/public/logo';
import VisitRequestDialog from '@/components/public/visit-request-dialog';
import { VisitDialogContext } from '@/components/public/visit-dialog-context';
import { usePublicLocale } from '@/lib/public-i18n';
import type { Brand } from '@/types';

/** A resolved menu entry (App\Domain\Content\PublicNavigation). */
type NavEntry = {
    label: string;
    href?: string;
    external?: boolean;
    /** Language links reload the page in that language. */
    lang?: string;
    children?: NavEntry[];
};

type PublicNavigationProps = {
    main: NavEntry[];
    utility: NavEntry[];
    tagline: string | null;
};

/** Below this width the header shows the mobile menu (new-vision.css). */
const MOBILE_QUERY = '(max-width: 900px)';

/**
 * Public site chrome in the 2026-11-01 visual language
 * (design/app/new-vision/NewVision.tsx): header with the school's own menu
 * hierarchy as keyboard-accessible disclosures, full-screen mobile menu
 * with accordions, and footer. School name, logo, colours and contact come
 * from `brand`; the menu from `publicNavigation` — both tenant settings
 * (CLAUDE.md invariant #8), never hardcoded here.
 */
export default function PublicLayout({ children }: PropsWithChildren) {
    const { props, url } = usePage<{
        brand: Brand | null;
        publicNavigation: PublicNavigationProps | null;
    }>();
    const { brand, publicNavigation } = props;
    const path = url.split(/[?#]/)[0];
    const isCurrent = (href: string) =>
        href === '/'
            ? path === '/'
            : path === href || path.startsWith(`${href}/`);
    const [menuOpen, setMenuOpen] = useState(false);
    const [visitOpen, setVisitOpen] = useState(false);
    const headerRef = useRef<HTMLElement | null>(null);
    const toggleRef = useRef<HTMLButtonElement | null>(null);
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
    const mainEntries: NavEntry[] = isDefault
        ? (publicNavigation?.main ?? [])
        : (localeNav ?? []).map((item) => ({
              label: item.label,
              href: item.href,
          }));
    const languageEntry: NavEntry | null =
        switcher.length > 1
            ? {
                  label: t('language'),
                  children: switcher.map((item) => ({
                      label: item.label,
                      href: item.url,
                      lang: item.code,
                  })),
              }
            : null;
    const entries = languageEntry
        ? [...mainEntries, languageEntry]
        : mainEntries;
    const utility = isDefault ? (publicNavigation?.utility ?? []) : [];

    // The dialog opens from buttons anywhere on the page, not from a Radix
    // trigger, so it remembers the button to give focus back on close.
    const visitTrigger = useRef<HTMLElement | null>(null);
    const openVisitDialog = () => {
        visitTrigger.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setMenuOpen(false);
        setVisitOpen(true);
    };

    const closeDisclosures = () =>
        headerRef.current
            ?.querySelectorAll('details[open]')
            .forEach((details) => details.removeAttribute('open'));

    // Lock the page behind the full-screen mobile menu.
    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        const prior = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const wide = window.matchMedia(MOBILE_QUERY);
        const onChange = () => !wide.matches && setMenuOpen(false);
        wide.addEventListener('change', onChange);

        return () => {
            document.body.style.overflow = prior;
            wide.removeEventListener('change', onChange);
        };
    }, [menuOpen]);

    // Escape closes the mobile menu; a click outside closes dropdowns; any
    // Inertia visit closes both.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && menuOpen) {
                setMenuOpen(false);
                toggleRef.current?.focus();
            }
        };
        const onPointer = (event: PointerEvent) => {
            if (
                headerRef.current &&
                event.target instanceof Node &&
                !headerRef.current.contains(event.target)
            ) {
                closeDisclosures();
            }
        };
        const stop = router.on('navigate', () => {
            setMenuOpen(false);
            closeDisclosures();
        });
        window.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onPointer);

        return () => {
            window.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onPointer);
            stop();
        };
    }, [menuOpen]);

    const onDisclosureKey = (event: ReactKeyboardEvent<HTMLDetailsElement>) => {
        if (event.key === 'Escape' && event.currentTarget.open) {
            event.currentTarget.open = false;
            event.currentTarget.querySelector('summary')?.focus();
            event.stopPropagation();
        }
    };

    const renderLink = (item: NavEntry, key: string) => {
        const content = (
            <>
                {item.label}
                <ArrowUpRight size={14} aria-hidden="true" />
            </>
        );

        if (item.lang) {
            return (
                <a
                    key={key}
                    href={item.href}
                    lang={item.lang}
                    hrefLang={item.lang}
                    aria-current={
                        switcher.find((s) => s.code === item.lang)?.current
                            ? 'true'
                            : undefined
                    }
                >
                    {content}
                </a>
            );
        }

        if (item.external) {
            return (
                <a
                    key={key}
                    href={item.href}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {item.label}
                    <ArrowUpRight size={14} aria-hidden="true" />
                    <span className="sr-only"> ({t('opensNewTab')})</span>
                </a>
            );
        }

        return (
            <Link
                key={key}
                href={item.href ?? '/'}
                aria-current={
                    item.href && isCurrent(item.href) ? 'page' : undefined
                }
                onClick={() => setMenuOpen(false)}
            >
                {content}
            </Link>
        );
    };

    const renderNavigation = (
        items: NavEntry[],
        place: 'desktop' | 'mobile',
        depth = 0,
    ) =>
        items.map((item, index) => {
            const key = `${place}-${depth}-${index}-${item.label}`;

            return item.children ? (
                <details
                    key={key}
                    // One open group per level, like the design.
                    name={`an-nav-${place}-${depth}`}
                    className={`an-nav-group an-nav-depth-${depth}`}
                    onKeyDown={onDisclosureKey}
                >
                    <summary>
                        {item.label}
                        <ChevronRight size={14} aria-hidden="true" />
                    </summary>
                    <div className="an-nav-dropdown">
                        {renderNavigation(item.children, place, depth + 1)}
                    </div>
                </details>
            ) : (
                renderLink(item, key)
            );
        });

    return (
        <VisitDialogContext.Provider value={openVisitDialog}>
            <div style={brandVars} className="aisi-next">
                <a className="an-skip" href="#main">
                    {t('skipToContent')}
                </a>
                <header ref={headerRef} className="an-header">
                    <Link
                        href={homeUrl}
                        className="an-logo"
                        aria-label={`${name} — ${t('home')}`}
                    >
                        <Logo brand={brand} size="plain" />
                    </Link>
                    <nav className="an-desktop-nav" aria-label={t('mainNav')}>
                        {renderNavigation(entries, 'desktop')}
                    </nav>
                    <div className="an-header-actions">
                        <Link href={login()} className="an-portal-link">
                            {t('myPortal', { name })}{' '}
                            <ArrowUpRight size={15} aria-hidden="true" />
                        </Link>
                        <button
                            type="button"
                            className="an-header-visit"
                            onClick={openVisitDialog}
                        >
                            {t('visitCta')}{' '}
                            <ArrowUpRight size={19} aria-hidden="true" />
                        </button>
                        <button
                            ref={toggleRef}
                            type="button"
                            className="an-menu-toggle"
                            aria-label={
                                menuOpen ? t('closeMenu') : t('openMenu')
                            }
                            aria-expanded={menuOpen}
                            aria-controls="an-mobile-menu"
                            onClick={() => setMenuOpen((open) => !open)}
                        >
                            {menuOpen ? (
                                <X aria-hidden="true" />
                            ) : (
                                <Menu aria-hidden="true" />
                            )}
                        </button>
                    </div>
                </header>
                {menuOpen && (
                    <nav
                        id="an-mobile-menu"
                        className="an-mobile-menu"
                        aria-label={t('mobileNav')}
                    >
                        {renderNavigation(entries, 'mobile')}
                        <Link href={login()} onClick={() => setMenuOpen(false)}>
                            {t('myPortal', { name })}{' '}
                            <ArrowUpRight aria-hidden="true" />
                        </Link>
                        <button type="button" onClick={openVisitDialog}>
                            {t('visitCta')} <ArrowUpRight aria-hidden="true" />
                        </button>
                        {(utility.length > 0 || !isDefault) && (
                            <div className="an-mobile-utility">
                                {utility.map((item, index) =>
                                    renderLink(item, `mobile-utility-${index}`),
                                )}
                                {!isDefault && (
                                    <a href="/" lang="ka">
                                        {t('inOriginal')}
                                    </a>
                                )}
                            </div>
                        )}
                    </nav>
                )}

                <main id="main">{children}</main>

                <footer className="an-footer">
                    <div className="an-wrap">
                        <div className="an-footer-top">
                            <Link
                                href={homeUrl}
                                className="an-footer-logo"
                                aria-label={`${name} — ${t('home')}`}
                            >
                                <Logo brand={brand} size="plain" />
                            </Link>
                            <p>
                                {(isDefault && publicNavigation?.tagline) || ''}
                            </p>
                            {brand?.contact.phone && (
                                <a
                                    href={`tel:${brand.contact.phone.replace(/\s+/g, '')}`}
                                >
                                    {brand.contact.phone}{' '}
                                    <ArrowUpRight
                                        size={16}
                                        aria-hidden="true"
                                    />
                                </a>
                            )}
                            {brand?.contact.email && (
                                <a href={`mailto:${brand.contact.email}`}>
                                    {brand.contact.email}{' '}
                                    <ArrowUpRight
                                        size={16}
                                        aria-hidden="true"
                                    />
                                </a>
                            )}
                        </div>
                        {(utility.length > 0 || !isDefault) && (
                            <nav className="an-footer-links" aria-label={name}>
                                {utility.map((item, index) =>
                                    renderLink(item, `footer-utility-${index}`),
                                )}
                                {!isDefault && (
                                    <a href="/" lang="ka">
                                        {t('inOriginal')}
                                    </a>
                                )}
                            </nav>
                        )}
                        <div className="an-footer-bottom">
                            <span>{brand?.contact.address ?? ''}</span>
                            <p className="an-footer-credit">
                                {t('madeByBefore')}
                                <a
                                    href="https://syslab.ge"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    syslab.ge
                                </a>
                                {t('madeByAfter')}
                            </p>
                            <span>
                                {name} © {new Date().getFullYear()}
                            </span>
                        </div>
                    </div>
                </footer>

                <VisitRequestDialog
                    open={visitOpen}
                    onOpenChange={setVisitOpen}
                    returnFocusTo={visitTrigger}
                />
            </div>
        </VisitDialogContext.Provider>
    );
}
