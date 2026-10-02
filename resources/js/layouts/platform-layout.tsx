import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import { ShieldHalf } from 'lucide-react';

/**
 * Chrome for the platform operator console — deliberately different from
 * a school's branded portal so an operator always knows where they are.
 */
export default function PlatformLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-slate-100 text-slate-900">
            <header className="border-b border-slate-300 bg-slate-900 text-white">
                <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
                    <ShieldHalf aria-hidden />
                    <Link href="/platform-admin" className="font-semibold">
                        პლატფორმის ადმინისტრირება
                    </Link>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>
        </div>
    );
}
