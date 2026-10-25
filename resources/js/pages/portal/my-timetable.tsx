import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type LessonStatus =
    | 'regular'
    | 'cancelled'
    | 'substituted'
    | 'room_changed'
    | 'covering'
    | 'handed_over';

type Entry = {
    id: number;
    subject: string;
    startsAt: string;
    endsAt: string;
    period: number | null;
    teacherName: string;
    regularTeacherName: string;
    roomName: string | null;
    className: string;
    groupName: string | null;
    status: LessonStatus;
    openable: boolean;
};

type Day = { date: string; isToday: boolean; lessons: Entry[] };

type Props = {
    role: 'student' | 'guardian' | 'teacher';
    view: 'day' | 'week';
    date: string;
    today: string;
    weekStart: string;
    previousDate: string;
    nextDate: string;
    state: 'ok' | 'no_children' | 'not_linked' | 'outside_year';
    children: { id: number; name: string; className: string | null }[];
    selectedChildId: number | null;
    days: Day[];
};

// Fixed labels: some browsers ship without Georgian weekday data.
const WEEKDAYS = [
    'კვირა',
    'ორშაბათი',
    'სამშაბათი',
    'ოთხშაბათი',
    'ხუთშაბათი',
    'პარასკევი',
    'შაბათი',
];

const STATUS: Record<
    Exclude<LessonStatus, 'regular'>,
    { label: string; className: string }
> = {
    cancelled: {
        label: 'გაუქმებულია',
        className: 'bg-rose-100 text-rose-900',
    },
    substituted: {
        label: 'ჩანაცვლება',
        className: 'bg-amber-100 text-amber-900',
    },
    room_changed: {
        label: 'ოთახი შეიცვალა',
        className: 'bg-sky-100 text-sky-900',
    },
    covering: {
        label: 'თქვენ ანაცვლებთ',
        className: 'bg-amber-100 text-amber-900',
    },
    handed_over: {
        label: 'გადაცემულია შემცვლელზე',
        className: 'bg-slate-200 text-slate-800',
    },
};

const MONTHS = [
    'იანვარი',
    'თებერვალი',
    'მარტი',
    'აპრილი',
    'მაისი',
    'ივნისი',
    'ივლისი',
    'აგვისტო',
    'სექტემბერი',
    'ოქტომბერი',
    'ნოემბერი',
    'დეკემბერი',
];

// "26 ოქტომბერი" — from the ISO date itself, so no timezone shift.
const formatDay = (isoDate: string) => {
    const [, month, day] = isoDate.split('-').map(Number);
    return `${day} ${MONTHS[month - 1]}`;
};

const weekday = (isoDate: string) =>
    WEEKDAYS[new Date(`${isoDate}T12:00:00Z`).getUTCDay()];

const EMPTY_STATE: Record<Exclude<Props['state'], 'ok'>, string> = {
    no_children:
        'თქვენს ანგარიშზე განრიგის სანახავად ნებადართული ბავშვი არ არის. თუ ეს შეცდომაა, მიმართეთ სკოლას.',
    not_linked:
        'თქვენი ანგარიში მოსწავლის ჩანაწერს ჯერ არ არის დაკავშირებული. მიმართეთ სკოლას.',
    outside_year:
        'ეს თარიღი სასწავლო წლის გარეთაა — განრიგი ამ დღეებისთვის არ არის.',
};

/**
 * Personal timetable: the signed-in pupil's lessons, a guardian's
 * authorised child's lessons, or a teacher's own lessons and covers — a
 * week (columns on wide screens, stacked days on phones) or a single day.
 * Who is shown is decided by the server; the child picker only chooses
 * among the guardian's own authorised children.
 */
export default function MyTimetable({
    role,
    view,
    date,
    today,
    weekStart,
    previousDate,
    nextDate,
    state,
    children,
    selectedChildId,
    days,
}: Props) {
    const query = (overrides: Record<string, string | number | null>) => {
        const params: Record<string, string> = { view, date };
        if (selectedChildId !== null) params.child = String(selectedChildId);
        for (const [key, value] of Object.entries(overrides)) {
            if (value === null) delete params[key];
            else params[key] = String(value);
        }
        return `/portal/my-timetable?${new URLSearchParams(params).toString()}`;
    };

    const title = role === 'guardian' ? 'შვილის განრიგი' : 'ჩემი განრიგი';
    const heading =
        view === 'day'
            ? `${weekday(date)}, ${formatDay(date)}`
            : `კვირის დასაწყისი: ${formatDay(weekStart)}`;

    return (
        <PortalLayout>
            <Head title={title} />
            <h1 className="mb-4 text-2xl">{title}</h1>

            {role === 'guardian' && children.length > 1 && (
                <div className="mb-4 max-w-sm">
                    <label
                        htmlFor="child"
                        className="mb-1 block text-sm font-medium"
                    >
                        ბავშვი
                    </label>
                    <select
                        id="child"
                        value={selectedChildId ?? ''}
                        onChange={(event) =>
                            router.get(
                                query({ child: event.target.value }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                    >
                        {children.map((child) => (
                            <option key={child.id} value={child.id}>
                                {child.name}
                                {child.className ? ` · ${child.className}` : ''}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {role === 'guardian' && children.length === 1 && (
                <p className="mb-4 text-sm text-slate-600">
                    {children[0].name}
                    {children[0].className ? ` · ${children[0].className}` : ''}
                </p>
            )}

            <nav
                aria-label="განრიგის ნავიგაცია"
                className="mb-4 flex flex-wrap items-center gap-2"
            >
                <div
                    role="group"
                    aria-label="ხედი"
                    className="inline-flex rounded-lg ring-1 ring-slate-200"
                >
                    {(['day', 'week'] as const).map((option) => (
                        <Link
                            key={option}
                            href={query({ view: option })}
                            aria-current={view === option ? 'page' : undefined}
                            className={`inline-flex min-h-11 items-center px-4 text-sm first:rounded-l-lg last:rounded-r-lg ${
                                view === option
                                    ? 'bg-[var(--brand-primary)] font-medium text-white'
                                    : 'bg-white text-slate-700'
                            }`}
                        >
                            {option === 'day' ? 'დღე' : 'კვირა'}
                        </Link>
                    ))}
                </div>
                <div className="inline-flex gap-2">
                    <Link
                        href={query({ date: previousDate })}
                        aria-label={view === 'day' ? 'წინა დღე' : 'წინა კვირა'}
                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-white ring-1 ring-slate-200"
                    >
                        <ChevronLeft size={18} aria-hidden />
                    </Link>
                    <Link
                        href={query({ date: today })}
                        className="inline-flex min-h-11 items-center rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200"
                    >
                        დღეს
                    </Link>
                    <Link
                        href={query({ date: nextDate })}
                        aria-label={
                            view === 'day' ? 'შემდეგი დღე' : 'შემდეგი კვირა'
                        }
                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-white ring-1 ring-slate-200"
                    >
                        <ChevronRight size={18} aria-hidden />
                    </Link>
                </div>
                <label className="sr-only" htmlFor="pick-date">
                    თარიღის არჩევა
                </label>
                <input
                    id="pick-date"
                    type="date"
                    value={date}
                    onChange={(event) =>
                        event.target.value &&
                        router.get(query({ date: event.target.value }))
                    }
                    className="h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                />
            </nav>

            <p className="mb-4 text-sm text-slate-500" aria-live="polite">
                {heading}
            </p>

            {state !== 'ok' ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <CalendarDays className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">{EMPTY_STATE[state]}</p>
                </div>
            ) : (
                <div
                    className={
                        view === 'week'
                            ? 'space-y-4 lg:grid lg:auto-cols-fr lg:grid-flow-col lg:gap-3 lg:space-y-0'
                            : ''
                    }
                >
                    {days.map((day) => (
                        <DayColumn
                            key={day.date}
                            day={day}
                            role={role}
                            showHeading={view === 'week'}
                        />
                    ))}
                </div>
            )}
        </PortalLayout>
    );
}

function DayColumn({
    day,
    role,
    showHeading,
}: {
    day: Day;
    role: Props['role'];
    showHeading: boolean;
}) {
    const headingId = `day-${day.date}`;

    return (
        <section
            aria-labelledby={showHeading ? headingId : undefined}
            className="min-w-0"
        >
            {showHeading && (
                <h2
                    id={headingId}
                    className={`mb-2 text-base font-medium ${day.isToday ? 'text-[var(--brand-primary)]' : ''}`}
                >
                    {weekday(day.date)}
                    <span className="ml-2 text-sm font-normal text-slate-500">
                        {formatDay(day.date)}
                        {day.isToday && ' · დღეს'}
                    </span>
                </h2>
            )}
            {day.lessons.length === 0 ? (
                <p className="rounded-xl bg-white p-4 text-sm text-slate-500 ring-1 ring-slate-200">
                    გაკვეთილი არ არის.
                </p>
            ) : (
                <ol className="space-y-2">
                    {day.lessons.map((entry) => (
                        <LessonCard key={entry.id} entry={entry} role={role} />
                    ))}
                </ol>
            )}
        </section>
    );
}

function LessonCard({ entry, role }: { entry: Entry; role: Props['role'] }) {
    const struck = entry.status === 'cancelled';
    const badge = entry.status === 'regular' ? null : STATUS[entry.status];
    const who =
        role === 'teacher'
            ? `${entry.className}${entry.groupName ? ` · ${entry.groupName}` : ''}`
            : entry.teacherName;

    const body = (
        <>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className={`font-medium ${struck ? 'line-through' : ''}`}>
                    {entry.subject}
                </p>
                <p className="text-sm text-slate-600 tabular-nums">
                    {entry.period !== null && `${entry.period}. `}
                    {entry.startsAt}–{entry.endsAt}
                </p>
            </div>
            <p className="text-sm text-slate-600">
                {who}
                {entry.roomName && ` · ${entry.roomName}`}
                {role !== 'teacher' &&
                    entry.groupName &&
                    ` · ${entry.groupName}`}
            </p>
            {entry.status === 'substituted' && role !== 'teacher' && (
                <p className="text-xs text-slate-500">
                    ძირითადი მასწავლებელი: {entry.regularTeacherName}
                </p>
            )}
            {badge && (
                <span
                    className={`mt-2 inline-flex rounded-full px-3 py-1 text-xs font-medium ${badge.className}`}
                >
                    {badge.label}
                </span>
            )}
        </>
    );

    return (
        <li>
            {entry.openable ? (
                <Link
                    href={`/portal/teacher/lessons/${entry.id}`}
                    className="block rounded-xl bg-white p-3 ring-1 ring-slate-200 hover:ring-slate-400 focus-visible:ring-2"
                >
                    {body}
                </Link>
            ) : (
                <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                    {body}
                </div>
            )}
        </li>
    );
}
