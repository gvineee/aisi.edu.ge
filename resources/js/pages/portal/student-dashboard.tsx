import { Head } from '@inertiajs/react';
import { CalendarClock, UserX } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type ScheduleEntry = {
    subject: string;
    startsAt: string;
    endsAt: string;
    teacherName: string;
    roomName: string | null;
    cancelled: boolean;
};

type Props = {
    linked: boolean;
    className: string | null;
    todaySchedule: ScheduleEntry[];
};

/**
 * "linked: false" is a real, honest state — this account holds an active
 * student membership but no Student record has been linked to it yet
 * (students.user_id). It is not an error to paper over with fake schedule
 * data; it means administration hasn't connected the two records.
 */
export default function StudentDashboard({
    linked,
    className,
    todaySchedule,
}: Props) {
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
                {className && ` · ${className}`}
            </p>

            {!linked ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <UserX className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        თქვენი ანგარიში ჯერ არ არის დაკავშირებული მოსწავლის
                        ჩანაწერთან.
                    </p>
                    <p className="mt-2 text-sm text-slate-500">
                        დაუკავშირდით სკოლის ადმინისტრაციას, რომ თქვენი განრიგი
                        გამოჩნდეს.
                    </p>
                </div>
            ) : (
                <section className="rounded-xl border border-slate-200 bg-white p-6">
                    <div className="mb-4 flex items-center gap-2">
                        <CalendarClock size={18} className="text-slate-500" />
                        <h2 className="text-base font-semibold">
                            დღევანდელი განრიგი
                        </h2>
                    </div>

                    {todaySchedule.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            დღეს გამოქვეყნებული გაკვეთილი არ არის.
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {todaySchedule.map((lesson, index) => (
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
                                        {lesson.startsAt}–{lesson.endsAt}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </PortalLayout>
    );
}
