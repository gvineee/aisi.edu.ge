import { Link } from '@inertiajs/react';

type Props = {
    tag: string;
    title: string;
    excerpt?: string | null;
    dateLabel: string;
    href: string;
};

/**
 * design/app/AisiConcept.tsx's `.notice` — a school-news item inside the
 * "today" screen's panel. Fed real published Post rows only; the news
 * panel that contains this shows an honest empty state when there are none
 * (never a placeholder announcement).
 */
export default function NoticeCard({
    tag,
    title,
    excerpt,
    dateLabel,
    href,
}: Props) {
    return (
        <Link
            href={href}
            className="block border-b border-slate-100 py-4 text-left last:border-0"
        >
            <span className="inline-block rounded bg-[#fff0e8] px-2 py-1 text-[10px] font-semibold text-[#a43d1e]">
                {tag}
            </span>
            <h4 className="mt-3 mb-2 text-sm font-semibold">{title}</h4>
            {excerpt && (
                <p className="text-xs leading-relaxed text-slate-600">
                    {excerpt}
                </p>
            )}
            <small className="mt-2 block text-[10px] text-slate-400">
                {dateLabel}
            </small>
        </Link>
    );
}
