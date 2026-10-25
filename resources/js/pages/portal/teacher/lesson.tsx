import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft, Video } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDay } from '@/lib/assessment';

type Props = {
    lesson: {
        id: number;
        subject: string | null;
        className: string | null;
        groupName: string | null;
        startsAt: string;
        endsAt: string;
        room: string | null;
        onlineUrl: string | null;
        heldToday: boolean;
    };
    todayTopic: string | null;
    recentTopics: { date: string; topic: string }[];
    isSubstitute: boolean;
    date: string;
    roster: { id: number; name: string; attendance: string | null }[];
    assignments: {
        id: number;
        title: string;
        status: string;
        dueAt: string | null;
    }[];
    handoff: {
        plan: string;
        materials: string | null;
        notes: string | null;
    } | null;
};

const ATTENDANCE: Record<string, string> = {
    present: 'დაესწრო',
    late: 'დააგვიანა',
    absent: 'არ დაესწრო',
    excused: 'საპატიო',
};

/**
 * One lesson: today's class-register topic, roster with today's attendance
 * and (for the lesson's own teacher) recent topics and the class's recent
 * assignments. A substitute sees only today's roster and entry.
 */
export default function TeacherLesson({
    lesson,
    isSubstitute,
    date,
    roster,
    assignments,
    handoff,
    todayTopic,
    recentTopics,
}: Props) {
    const [saving, setSaving] = useState(false);

    const saveTopic = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        form.set('occurs_on', date);
        setSaving(true);
        router.post(`/portal/teacher/lessons/${lesson.id}/record`, form, {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'ვერ შეინახა.'),
            onFinish: () => setSaving(false),
        });
    };

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
                {lesson.groupName ? ` · ${lesson.groupName}` : ''}
            </h1>
            <p className="mb-4 text-sm text-slate-500">
                {formatDay(date)} · {lesson.startsAt}–{lesson.endsAt}
                {lesson.room ? ` · ${lesson.room}` : ''}
                {isSubstitute &&
                    ' · ჩანაცვლება (მხოლოდ დღევანდელი გაკვეთილისთვის)'}
            </p>
            {isSubstitute && (
                <section
                    aria-labelledby="lesson-handoff"
                    className="mb-6 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200"
                >
                    <h2 id="lesson-handoff" className="mb-2 text-lg">
                        გეგმა შემცვლელისთვის
                    </h2>
                    {handoff ? (
                        <div className="space-y-2 text-sm whitespace-pre-line">
                            <p>{handoff.plan}</p>
                            {handoff.materials && (
                                <p>
                                    <strong>მასალები:</strong>{' '}
                                    {handoff.materials}
                                </p>
                            )}
                            {handoff.notes && <p>{handoff.notes}</p>}
                        </div>
                    ) : (
                        <p className="text-sm text-slate-600">
                            მასწავლებელს გეგმა ჯერ არ დაუტოვებია.
                        </p>
                    )}
                </section>
            )}
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

            <section className="mb-6" aria-labelledby="lesson-topic">
                <h2 id="lesson-topic" className="mb-2 text-lg">
                    გაკვეთილის თემა (ჟურნალი)
                </h2>
                {lesson.heldToday ? (
                    <form
                        onSubmit={saveTopic}
                        className="space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200"
                    >
                        <label htmlFor="topic" className="sr-only">
                            დღევანდელი გაკვეთილის თემა
                        </label>
                        <textarea
                            id="topic"
                            name="topic"
                            rows={3}
                            maxLength={2000}
                            defaultValue={todayTopic ?? ''}
                            placeholder="რა ისწავლეს დღეს"
                            className="w-full rounded-md border border-slate-300 p-3 text-sm"
                        />
                        <button
                            type="submit"
                            disabled={saving}
                            className="inline-flex min-h-11 items-center rounded-lg bg-[var(--brand-primary)] px-4 text-sm font-medium text-white disabled:opacity-60"
                        >
                            თემის შენახვა
                        </button>
                    </form>
                ) : (
                    <p className="text-sm text-slate-500">
                        ეს გაკვეთილი დღეს არ ტარდება — თემა მისი დღისთვის
                        ჩაიწერება.
                    </p>
                )}
                {recentTopics.length > 0 && (
                    <ul className="mt-3 space-y-1 text-sm">
                        {recentTopics.map((entry) => (
                            <li key={entry.date}>
                                <span className="text-slate-500">
                                    {formatDay(entry.date)}:
                                </span>{' '}
                                {entry.topic}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

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
