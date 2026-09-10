import { Head, Link } from '@inertiajs/react';
import { CalendarClock, ClipboardList, FileText } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type LessonEntry = {
    lessonId: number;
    subject: string;
    className: string;
    startsAt: string;
    endsAt: string;
    roomName: string | null;
    cancelled: boolean;
};

type Props = {
    date: string;
    lessons: LessonEntry[];
};

export default function TeacherDashboard({ date, lessons }: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <div className="mb-2 flex items-center justify-between">
                <h1 className="text-2xl">დღევანდელი გაკვეთილები</h1>
                <Link
                    href="/documents"
                    className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium"
                >
                    <FileText size={16} /> დოკუმენტები
                </Link>
            </div>
            <p className="mb-8 text-sm text-slate-500">
                {new Date(date).toLocaleDateString('ka-GE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                })}
            </p>

            {lessons.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <CalendarClock className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        დღეს გამოქვეყნებული გაკვეთილი არ გაქვთ.
                    </p>
                </div>
            ) : (
                <ul className="space-y-3">
                    {lessons.map((lesson) => (
                        <li
                            key={lesson.lessonId}
                            className={`flex items-center justify-between rounded-xl border border-slate-200 bg-white p-5 ${
                                lesson.cancelled ? 'opacity-50' : ''
                            }`}
                        >
                            <div>
                                <p className="text-xs text-slate-500 tabular-nums">
                                    {lesson.startsAt}–{lesson.endsAt}
                                </p>
                                <p className="mt-1 font-medium">
                                    {lesson.subject} · {lesson.className}
                                    {lesson.cancelled && (
                                        <span className="ml-2 text-xs text-red-600">
                                            გაუქმებულია
                                        </span>
                                    )}
                                </p>
                                {lesson.roomName && (
                                    <p className="text-sm text-slate-500">
                                        {lesson.roomName}
                                    </p>
                                )}
                            </div>
                            {!lesson.cancelled && (
                                <Link
                                    href={`/lessons/${lesson.lessonId}/attendance?date=${date}`}
                                    className="inline-flex items-center gap-2 rounded-lg bg-[var(--brand-primary)] px-4 py-2 text-sm font-medium text-white"
                                >
                                    <ClipboardList size={16} /> დასწრება
                                </Link>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
