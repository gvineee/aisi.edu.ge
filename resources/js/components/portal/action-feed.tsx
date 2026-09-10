import { Link } from '@inertiajs/react';
import { CheckCircle2, FileCheck2, MessageSquareText } from 'lucide-react';

export type ActionItem = {
    key: string;
    type: string;
    title: string;
    contextLabel: string | null;
    href: string;
};

const ICONS: Record<string, typeof FileCheck2> = {
    document_approval: FileCheck2,
    unread_message: MessageSquareText,
};

/**
 * Aggregated "needs your attention" list (CLAUDE-PLATFORM-MODULES.md §3's
 * დღის ცენტრი) — every item is a real, currently-pending thing this user
 * can act on right now, resolved server-side by BuildDailyActionFeed.
 * Empty means the day is genuinely clear, not that the feature is missing.
 */
export default function ActionFeed({ items }: { items: ActionItem[] }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-6">
            <h3 className="mb-4 text-base font-semibold">დღის მოქმედებები</h3>

            {items.length === 0 ? (
                <div className="py-6 text-center">
                    <CheckCircle2 className="mx-auto mb-3 h-8 w-8 text-slate-400" />
                    <p className="text-sm text-slate-500">
                        დღეს ყველაფერი მოგვარებულია.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {items.map((item) => {
                        const Icon = ICONS[item.type] ?? FileCheck2;

                        return (
                            <li key={item.key} className="py-1">
                                <Link
                                    href={item.href}
                                    className="flex min-h-11 items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-slate-50"
                                >
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#FFF1EA] text-[#C84925]">
                                        <Icon size={18} />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {item.title}
                                        </span>
                                        {item.contextLabel && (
                                            <span className="block truncate text-xs text-slate-500">
                                                {item.contextLabel}
                                            </span>
                                        )}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
