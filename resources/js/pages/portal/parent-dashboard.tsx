import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { BookMarked, CalendarClock, Users } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';

type ScheduleEntry = {
    subject: string;
    startsAt: string;
    endsAt: string;
    teacherName: string;
    roomName: string | null;
    cancelled: boolean;
};

type Child = {
    id: number;
    name: string;
    className: string | null;
    permissions: {
        academic: boolean;
        financial: boolean;
        pickup: boolean;
        notifications: boolean;
    };
    todaySchedule: ScheduleEntry[];
};

type Props = {
    children: Child[];
    actionItems: ActionItem[];
};

/**
 * Every value here comes from the authenticated guardian's own active
 * guardian_links (DashboardController) — never a client-supplied child id,
 * and never demo data. An empty list is a real, honest state, not
 * something papered over with placeholders.
 */
export default function ParentDashboard({ children, actionItems }: Props) {
    const [selectedId, setSelectedId] = useState(children[0]?.id ?? null);
    const selected = children.find((c) => c.id === selectedId) ?? null;

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <h1 className="mb-2 text-2xl">დღეს</h1>
            <p className="mb-8 text-sm text-slate-500">
                {new Date().toLocaleDateString('ka-GE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                })}
            </p>

            <div className="mb-6">
                <ActionFeed items={actionItems} />
            </div>

            {children.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <Users className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        თქვენს ანგარიშზე ჯერ არცერთი ბავშვი არ არის
                        დაკავშირებული.
                    </p>
                    <p className="mt-2 text-sm text-slate-500">
                        დაუკავშირდით სკოლის ადმინისტრაციას, თუ ეს მოსალოდნელი არ
                        იყო.
                    </p>
                </div>
            ) : (
                <>
                    {children.length > 1 && (
                        <div
                            className="mb-6 flex gap-2"
                            role="tablist"
                            aria-label="ბავშვის არჩევა"
                        >
                            {children.map((child) => (
                                <button
                                    key={child.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={selectedId === child.id}
                                    onClick={() => setSelectedId(child.id)}
                                    className={`min-h-11 rounded-full border px-4 py-2 text-sm font-medium ${
                                        selectedId === child.id
                                            ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-white'
                                            : 'border-slate-300 bg-white'
                                    }`}
                                >
                                    {child.name}
                                </button>
                            ))}
                        </div>
                    )}

                    {selected && (
                        <>
                            <section className="rounded-xl bg-[var(--brand-primary)] p-8 text-white">
                                <p className="text-xs tracking-wide text-white/70">
                                    {selected.className ??
                                        'კლასი მინიჭებული არ არის'}
                                </p>
                                <h2 className="mt-2 text-xl">
                                    {selected.name}-ის დღე
                                </h2>
                                <ul className="mt-6 flex flex-wrap gap-3 text-xs">
                                    {selected.permissions.academic && (
                                        <li className="rounded-full bg-white/15 px-3 py-1">
                                            აკადემიური ინფორმაცია
                                        </li>
                                    )}
                                    {selected.permissions.financial && (
                                        <li className="rounded-full bg-white/15 px-3 py-1">
                                            ფინანსები
                                        </li>
                                    )}
                                    {selected.permissions.pickup && (
                                        <li className="rounded-full bg-white/15 px-3 py-1">
                                            წაყვანის უფლება
                                        </li>
                                    )}
                                </ul>
                            </section>

                            {selected.permissions.academic && (
                                <div className="mt-4">
                                    <Link
                                        href={`/portal/students/${selected.id}/portfolio`}
                                        className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium hover:border-slate-300"
                                    >
                                        <BookMarked size={16} /> {selected.name}
                                        -ის პორტფოლიო
                                    </Link>
                                </div>
                            )}

                            <section className="mt-6 rounded-xl border border-slate-200 bg-white p-6">
                                <div className="mb-4 flex items-center gap-2">
                                    <CalendarClock
                                        size={18}
                                        className="text-slate-500"
                                    />
                                    <h3 className="text-base font-semibold">
                                        დღევანდელი განრიგი
                                    </h3>
                                </div>

                                {!selected.permissions.academic ? (
                                    <p className="text-sm text-slate-500">
                                        აკადემიური ინფორმაციის ნახვის უფლება არ
                                        გაქვთ ამ ბავშვისთვის.
                                    </p>
                                ) : selected.todaySchedule.length === 0 ? (
                                    <p className="text-sm text-slate-500">
                                        დღეს გამოქვეყნებული გაკვეთილი არ არის.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-slate-100">
                                        {selected.todaySchedule.map(
                                            (lesson, index) => (
                                                <li
                                                    key={index}
                                                    className={`flex items-center justify-between py-3 text-sm ${lesson.cancelled ? 'opacity-50' : ''}`}
                                                >
                                                    <div>
                                                        <p className="font-medium">
                                                            {lesson.subject}
                                                            {lesson.cancelled && (
                                                                <span className="ml-2 text-xs text-red-600">
                                                                    გაუქმებულია
                                                                </span>
                                                            )}
                                                        </p>
                                                        <p className="text-slate-500">
                                                            {lesson.teacherName}
                                                            {lesson.roomName &&
                                                                ` · ${lesson.roomName}`}
                                                        </p>
                                                    </div>
                                                    <span className="text-slate-500 tabular-nums">
                                                        {lesson.startsAt}–
                                                        {lesson.endsAt}
                                                    </span>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </section>
                        </>
                    )}
                </>
            )}
        </PortalLayout>
    );
}
