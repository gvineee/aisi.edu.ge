import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Video } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDay } from '@/lib/assessment';

type Props = {
    lesson: {
        id: number;
        subject: string | null;
        className: string | null;
        startsAt: string;
        endsAt: string;
        room: string | null;
        onlineUrl: string | null;
    };
    isSubstitute: boolean;
    date: string;
    roster: { id: number; name: string; attendance: string | null }[];
    assignments: {
        id: number;
        title: string;
        status: string;
        dueAt: string | null;
    }[];
};

const ATTENDANCE: Record<string, string> = {
    present: 'დაესწრო',
    late: 'დააგვიანა',
    absent: 'არ დაესწრო',
    excused: 'საპატიო',
};

/**
 * One lesson: roster with today's attendance and (for the lesson's own
 * teacher) the class's recent assignments. A substitute sees only today's
 * roster for this lesson.
 */
export default function TeacherLesson({
    lesson,
    isSubstitute,
    date,
    roster,
    assignments,
}: Props) {
    return (
        <PortalLayout>
            <Head
                title={`${lesson.subject ?? ''} — ${lesson.className ?? ''}`}
            />
            <Link
                href="/portal/teacher"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ჩემი სივრცე
            </Link>
            <h1 className="mb-1 text-2xl">
                {lesson.subject} · {lesson.className}
            </h1>
            <p className="mb-4 text-sm text-slate-500">
                {formatDay(date)} · {lesson.startsAt}–{lesson.endsAt}
                {lesson.room ? ` · ${lesson.room}` : ''}
                {isSubstitute &&
                    ' · ჩანაცვლება (მხოლოდ დღევანდელი გაკვეთილისთვის)'}
            </p>
            <div className="mb-6 flex flex-wrap gap-2">
                <Link
                    href={`/lessons/${lesson.id}/attendance?date=${date}`}
                    className="inline-flex min-h-11 items-center rounded-lg bg-[var(--brand-primary)] px-4 text-sm font-medium text-white"
                >
                    დასწრების მონიშვნა
                </Link>
                {lesson.onlineUrl && (
                    <a
                        href={lesson.onlineUrl}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        <Video size={16} aria-hidden /> ონლაინ გაკვეთილი
                    </a>
                )}
            </div>

            <section className="mb-6" aria-labelledby="roster">
                <h2 id="roster" className="mb-2 text-lg">
                    მოსწავლეები ({roster.length})
                </h2>
                <ul className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                    {roster.map((s) => (
                        <li key={s.id} className="flex justify-between p-3">
                            <span>{s.name}</span>
                            <span className="text-slate-500">
                                {s.attendance
                                    ? (ATTENDANCE[s.attendance] ?? s.attendance)
                                    : 'მოუნიშნავი'}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            {!isSubstitute && (
                <section aria-labelledby="lesson-assignments">
                    <h2 id="lesson-assignments" className="mb-2 text-lg">
                        დავალებები
                    </h2>
                    {assignments.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            დავალება ჯერ არ არის.
                        </p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {assignments.map((a) => (
                                <li key={a.id}>
                                    <Link
                                        href={`/portal/assignments/${a.id}/submissions`}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {a.title}
                                    </Link>{' '}
                                    · {a.status}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </PortalLayout>
    );
}
