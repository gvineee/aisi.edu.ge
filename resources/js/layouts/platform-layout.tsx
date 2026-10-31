import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import { ShieldHalf } from 'lucide-react';
import AccountMenu from '@/components/account-menu';

type Auth = { user: { name: string; email: string } | null };

/**
 * Chrome for the platform operator console — deliberately different from
 * a school's branded portal so an operator always knows where they are,
 * but in the same visual language (navy, headings, spacing, 44px targets)
 * and with the operator's own account menu (security, sign-out).
 */
export default function PlatformLayout({ children }: PropsWithChildren) {
    const page = usePage<{ auth: Auth }>();
    const { auth } = page.props;
    const path = page.url.split('?')[0];

    return (
        <div className="min-h-screen bg-[#F4F7FA] text-[#132B45]">
            <a
                href="#platform-main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:shadow-lg"
            >
                მთავარ შინაარსზე გადასვლა
            </a>
            <header className="border-b border-[#0d1f33] bg-[#132B45] text-white">
                <div className="mx-auto flex min-h-[64px] max-w-6xl flex-wrap items-center justify-between gap-3 px-4 sm:px-6">
                    <Link
                        href="/platform-admin"
                        className="font-heading inline-flex min-h-11 items-center gap-3 text-lg"
                    >
                        <ShieldHalf aria-hidden className="text-[#F5683C]" />
                        პლატფორმის ადმინისტრირება
                    </Link>
                    <nav
                        aria-label="პლატფორმის ნავიგაცია"
                        className="flex items-center gap-1 text-sm"
                    >
                        <Link
                            href="/platform-admin"
                            aria-current={
                                path === '/platform-admin' ? 'page' : undefined
                            }
                            className="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-white/10 aria-[current=page]:underline aria-[current=page]:decoration-[#F5683C] aria-[current=page]:decoration-2 aria-[current=page]:underline-offset-8"
                        >
                            სკოლები და გეგმები
                        </Link>
                        {auth.user && (
                            <div className="rounded-lg bg-white text-[#132B45]">
                                <AccountMenu
                                    user={auth.user}
                                    context="პლატფორმის ოპერატორი"
                                />
                            </div>
                        )}
                    </nav>
                </div>
            </header>
            <main
                id="platform-main"
                className="mx-auto max-w-6xl px-4 py-8 sm:px-6"
            >
                {children}
            </main>
        </div>
    );
}
