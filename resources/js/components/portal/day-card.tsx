import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

type Props = {
    eyebrow: string;
    heading: string;
    body: string;
    ctaLabel: string;
    ctaHref: string;
};

/**
 * design/app/AisiConcept.tsx's `.day-card`: the navy hero panel at the top
 * of every role's "today" screen.
 */
export default function DayCard({
    eyebrow,
    heading,
    body,
    ctaLabel,
    ctaHref,
}: Props) {
    return (
        <section
            className="rounded-2xl p-8"
            style={{ backgroundColor: 'var(--brand-primary)' }}
        >
            <p className="mb-3 text-xs font-bold tracking-widest text-[#b2c7d7]">
                {eyebrow}
            </p>
            <h2 className="max-w-md text-2xl leading-snug text-white">
                {heading}
            </h2>
            <p className="my-4 max-w-md text-sm text-[#c6d5df]">{body}</p>
            <Link
                href={ctaHref}
                className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-5 py-3 text-xs font-semibold"
                style={{ color: 'var(--brand-primary)' }}
            >
                {ctaLabel} <ArrowRight size={16} />
            </Link>
        </section>
    );
}
