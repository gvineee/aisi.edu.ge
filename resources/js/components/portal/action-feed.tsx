import { Link } from '@inertiajs/react';
import {
    CalendarCheck,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    FileCheck2,
    LifeBuoy,
    MessageSquareText,
    Receipt,
    Repeat,
    ScrollText,
    UserCheck,
} from 'lucide-react';

export type ActionItem = {
    key: string;
    type: string;
    title: string;
    contextLabel: string | null;
    href: string;
    dueAt?: string | null;
    priority?: 'urgent' | 'high' | 'normal';
};

const ICONS: Record<string, typeof FileCheck2> = {
    document_approval: FileCheck2,
    unread_message: MessageSquareText,
    consent_request: FileCheck2,
    invoice: Receipt,
    report_card: ScrollText,
    appointment: CalendarCheck,
    attendance: UserCheck,
    substitution: Repeat,
    grading: ClipboardCheck,
    assignment: ClipboardList,
    support_access: LifeBuoy,
};

const PRIORITY: Record<
    string,
    { label: string; className: string } | undefined
> = {
    urgent: { label: 'სასწრაფო', className: 'bg-red-100 text-red-800' },
    high: { label: 'მნიშვნელოვანი', className: 'bg-amber-100 text-amber-900' },
};

function dueLabel(iso: string): string {
    return new Date(iso).toLocaleString('ka-GE', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Tbilisi',
    });
}

/**
 * Aggregated "needs your attention" list (CLAUDE-PLATFORM-MODULES.md §3's
 * დღის ცენტრი) — every item is a real, currently-pending thing this user
 * can act on right now, resolved and ordered server-side by
 * BuildDailyActionFeed (urgent/overdue first). Empty means the day is
 * genuinely clear, not that the feature is missing.
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
                        const priority = item.priority
                            ? PRIORITY[item.priority]
                            : undefined;

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
                                        {(item.contextLabel || item.dueAt) && (
                                            <span className="block truncate text-xs text-slate-500">
                                                {[
                                                    item.contextLabel,
                                                    item.dueAt
                                                        ? `ვადა: ${dueLabel(item.dueAt)}`
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        )}
                                    </span>
                                    {priority && (
                                        <span
                                            className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${priority.className}`}
                                        >
                                            {priority.label}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
