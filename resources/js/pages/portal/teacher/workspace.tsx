import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardCheck,
    MessageSquareText,
    PenLine,
    ScrollText,
} from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDay } from '@/lib/assessment';

type Props = {
    date: string;
    lessons: {
        id: number;
        subject: string | null;
        className: string | null;
        startsAt: string;
        endsAt: string;
        room: string | null;
        isSubstitute: boolean;
        attendanceMarked: number;
        roster: number;
    }[];
    toGrade: number;
    draftAssessments: number;
    reportCardLines: number;
    unreadConversations: number;
    classes: {
        id: number;
        className: string | null;
        subject: string | null;
        students: number;
        isHomeroom: boolean;
    }[];
    homeroomClasses: string[];
};

function Tile({
    href,
    icon: Icon,
    value,
    label,
}: {
    href: string;
    icon: typeof PenLine;
    value: number;
    label: string;
}) {
    return (
        <Link
            href={href}
            className="flex flex-col gap-1 rounded-xl bg-white p-4 ring-1 ring-slate-200 hover:ring-slate-300 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
        >
            <Icon size={20} className="text-[#c84925]" aria-hidden />
            <span className="text-2xl font-semibold">{value}</span>
            <span className="text-sm text-slate-600">{label}</span>
        </Link>
    );
}

/**
 * Teacher home: today's lessons (own and substitute) with attendance
 * completion, work waiting for the teacher and their classes.
 */
export default function TeacherWorkspace({
    date,
    lessons,
    toGrade,
    draftAssessments,
    reportCardLines,
    unreadConversations,
    classes,
    homeroomClasses,
}: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი სივრცე" />
            <h1 className="mb-1 text-2xl">ჩემი სივრცე</h1>
            <p className="mb-2 text-sm text-slate-500">{formatDay(date)}</p>
            <Link
                href="/portal/my-coverage"
                className="mb-6 inline-flex min-h-11 items-center text-sm font-medium text-[#c84925] underline-offset-4 hover:underline"
            >
                ჩემი ჩანაცვლებები და გეგმები →
            </Link>

            <div className="mb-8 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Tile
                    href="/portal/assignments"
                    icon={ClipboardCheck}
                    value={toGrade}
                    label="შესაფასებელი ნამუშევარი"
                />
                <Tile
                    href="/portal/gradebook"
                    icon={PenLine}
                    value={draftAssessments}
                    label="გამოუქვეყნებელი შეფასება"
                />
                <Tile
                    href="/portal/report-cards"
                    icon={ScrollText}
                    value={reportCardLines}
                    label="ტაბელის შესავსები ხაზი"
                />
                <Tile
                    href="/portal/messages"
                    icon={MessageSquareText}
                    value={unreadConversations}
                    label="წაუკითხავი საუბარი"
                />
            </div>

            <section className="mb-8" aria-labelledby="today-lessons">
                <h2
                    id="today-lessons"
                    className="mb-3 flex items-center gap-2 text-lg"
                >
                    <CalendarClock size={20} aria-hidden /> დღევანდელი
                    გაკვეთილები
                </h2>
                {lessons.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                        დღეს გაკვეთილი არ გაქვთ.
                    </p>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl bg-white ring-1 ring-slate-200">
                        {lessons.map((l) => {
                            const done =
                                l.roster > 0 && l.attendanceMarked >= l.roster;

                            return (
                                <li key={`${l.id}-${l.isSubstitute}`}>
                                    <Link
                                        href={`/portal/teacher/lessons/${l.id}`}
                                        className="flex min-h-11 flex-wrap items-center justify-between gap-2 p-4 hover:bg-slate-50"
                                    >
                                        <span>
                                            <strong>
                                                {l.startsAt}–{l.endsAt}
                                            </strong>{' '}
                                            · {l.subject} · {l.className}
                                            {l.room ? ` · ${l.room}` : ''}
                                            {l.isSubstitute && (
                                                <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                                                    ჩანაცვლება
                                                </span>
                                            )}
                                        </span>
                                        <span
                                            className={`text-sm ${done ? 'text-emerald-700' : 'text-slate-500'}`}
                                        >
                                            დასწრება: {l.attendanceMarked}/
                                            {l.roster}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            <section aria-labelledby="my-classes">
                <h2 id="my-classes" className="mb-3 text-lg">
                    ჩემი კლასები
                </h2>
                {homeroomClasses.length > 0 && (
                    <p className="mb-2 text-sm text-slate-600">
                        დამრიგებელი: {homeroomClasses.join(', ')}
                    </p>
                )}
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {classes.map((c) => (
                        <li
                            key={c.id}
                            className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                        >
                            <p className="font-medium">
                                {c.className} · {c.subject}
                            </p>
                            <p className="text-sm text-slate-500">
                                {c.students} მოსწავლე
                                {c.isHomeroom ? ' · დამრიგებელი' : ''}
                            </p>
                            <Link
                                href={`/portal/gradebook?ta=${c.id}`}
                                className="mt-2 inline-flex min-h-11 items-center text-sm underline-offset-4 hover:underline"
                            >
                                შეფასებები
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>
        </PortalLayout>
    );
}
