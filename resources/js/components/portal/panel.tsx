import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { PropsWithChildren, ReactNode } from 'react';

type Props = PropsWithChildren<{
    title: string;
    action?: { label: string; href: string };
    trailing?: ReactNode;
    className?: string;
}>;

/**
 * design/app/AisiConcept.tsx's `.panel` + `.panel-heading` — the plain
 * white card used throughout the portal for lists (today's lessons,
 * school news, etc).
 */
export default function Panel({
    title,
    action,
    trailing,
    className,
    children,
}: Props) {
    return (
        <section
            className={`rounded-xl border border-slate-200 bg-white p-6 ${className ?? ''}`}
        >
            <div className="mb-4 flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold">{title}</h3>
                {action && (
                    <Link
                        href={action.href}
                        className="inline-flex items-center gap-1 text-xs font-semibold"
                        style={{ color: 'var(--brand-primary)' }}
                    >
                        {action.label} <ArrowUpRight size={14} />
                    </Link>
                )}
                {trailing}
            </div>
            {children}
        </section>
    );
}
