import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as LessonController from '@/actions/App/Http/Controllers/Portal/LessonController';
import * as TimetableSetupController from '@/actions/App/Http/Controllers/Portal/TimetableSetupController';

type AcademicYearOption = {
    id: number;
    name: string;
    isCurrent: boolean;
};

type SchoolClassOption = {
    id: number;
    name: string;
    academicYearId: number;
    academicYearName: string;
};

type SubjectOption = {
    id: number;
    name: string;
};

type RoomOption = {
    id: number;
    name: string;
};

type TeacherOption = {
    id: number;
    name: string;
};

type LessonRow = {
    id: number;
    schoolClassName: string;
    subjectName: string;
    teacherName: string;
    roomName: string | null;
    groupName: string | null;
    timetableVersionId: number | null;
    periodNumber: number | null;
    dayOfWeek: number;
    startsAt: string;
    endsAt: string;
    status: string;
};

type Props = {
    academicYears: AcademicYearOption[];
    schoolClasses: SchoolClassOption[];
    subjects: SubjectOption[];
    rooms: RoomOption[];
    teachers: TeacherOption[];
    lessons: LessonRow[];
    groups: GroupOption[];
    versions: Version[];
    bellPeriods: BellPeriodRow[];
};

type GroupOption = { id: number; name: string; schoolClassId: number };

type Version = {
    id: number;
    academicYearId: number;
    label: string;
    sourceVersion: string | null;
    validFrom: string;
    validTo: string | null;
    status: 'draft' | 'active' | 'archived';
};

type BellPeriodRow = {
    academicYearId: number;
    number: number;
    startsAt: string;
    endsAt: string;
};

const VERSION_STATUS: Record<Version['status'], string> = {
    draft: 'სამუშაო',
    active: 'აქტიური',
    archived: 'დაარქივებული',
};

const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

const DAY_LABELS: Record<number, string> = {
    1: 'ორშაბათი',
    2: 'სამშაბათი',
    3: 'ოთხშაბათი',
    4: 'ხუთშაბათი',
    5: 'პარასკევი',
    6: 'შაბათი',
    7: 'კვირა',
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'დრაფტი',
    published: 'გამოქვეყნებული',
};

/**
 * "განრიგი" — creates the subjects/lessons rows the Substitution module's
 * absence/coverage worklist depends on. Before this screen existed,
 * LessonController::store was unreachable dead code (no page posted to it)
 * and nothing anywhere could create a Subject — the confirmed production
 * root blocker in Substitution module verification (0 lessons, 0 subjects,
 * so no absence could ever surface a "needs coverage" row).
 */
export default function TimetableIndex({
    academicYears,
    schoolClasses,
    subjects,
    rooms,
    teachers,
    lessons,
    groups,
    versions,
    bellPeriods,
}: Props) {
    const [showSubjectForm, setShowSubjectForm] = useState(false);
    const [showLessonForm, setShowLessonForm] = useState(false);
    const [sending, setSending] = useState(false);
    const [yearId, setYearId] = useState<number | undefined>(
        academicYears.find((y) => y.isCurrent)?.id ?? academicYears[0]?.id,
    );
    const [classId, setClassId] = useState<number | null>(null);
    const [startsAt, setStartsAt] = useState('');
    const [endsAt, setEndsAt] = useState('');

    const yearPeriods = bellPeriods.filter((p) => p.academicYearId === yearId);
    const yearVersions = versions.filter((v) => v.academicYearId === yearId);
    const classGroups = groups.filter((g) => g.schoolClassId === classId);

    const pickPeriod = (value: string) => {
        const period = yearPeriods.find((p) => String(p.number) === value);
        if (period) {
            setStartsAt(period.startsAt);
            setEndsAt(period.endsAt);
        }
    };

    const submitSubject = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);

        router.post(LessonController.storeSubject.url(), form, {
            onSuccess: () => {
                setShowSubjectForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSending(false),
        });
    };

    const submitLesson = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);

        router.post(LessonController.store.url(), form, {
            onSuccess: () => {
                setShowLessonForm(false);
                (event.target as HTMLFormElement).reset();
                setStartsAt('');
                setEndsAt('');
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        'ვერ დაემატა — შეამოწმეთ ველები ან დროის კონფლიქტი.',
                ),
            onFinish: () => setSending(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="განრიგი" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">განრიგი</h1>
            </div>

            {yearId !== undefined && (
                <>
                    <div className="mb-6 max-w-xs">
                        <label
                            htmlFor="setup-year"
                            className="mb-1 block text-sm font-medium"
                        >
                            სასწავლო წელი (ზარები და ვერსიები)
                        </label>
                        <select
                            id="setup-year"
                            value={yearId}
                            onChange={(event) =>
                                setYearId(Number(event.target.value))
                            }
                            className={selectClass}
                        >
                            {academicYears.map((year) => (
                                <option key={year.id} value={year.id}>
                                    {year.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <BellSchedule
                        key={`bells-${yearId}`}
                        yearId={yearId}
                        periods={yearPeriods}
                    />
                    <Versions yearId={yearId} versions={yearVersions} />
                </>
            )}

            {/* Subjects */}
            <section className="mb-8">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg">საგნები</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowSubjectForm((v) => !v)}
                    >
                        <Plus size={16} /> ახალი საგანი
                    </Button>
                </div>

                {showSubjectForm && (
                    <form
                        onSubmit={submitSubject}
                        className="mb-4 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                    >
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                დასახელება
                            </label>
                            <Input
                                name="name"
                                placeholder="მაგ. მათემატიკა"
                                required
                            />
                        </div>
                        <Button type="submit" disabled={sending}>
                            შენახვა
                        </Button>
                    </form>
                )}

                {subjects.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <p className="font-medium">
                            საგანი ჯერ არ არის დამატებული.
                        </p>
                    </div>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {subjects.map((subject) => (
                            <li
                                key={subject.id}
                                className="rounded-full bg-slate-100 px-3 py-1 text-sm"
                            >
                                {subject.name}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Lessons */}
            <section>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg">გაკვეთილები</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowLessonForm((v) => !v)}
                        disabled={
                            schoolClasses.length === 0 ||
                            subjects.length === 0 ||
                            teachers.length === 0
                        }
                    >
                        <Plus size={16} /> ახალი გაკვეთილი
                    </Button>
                </div>

                {(schoolClasses.length === 0 ||
                    subjects.length === 0 ||
                    teachers.length === 0) && (
                    <p className="mb-4 text-sm text-slate-500">
                        გაკვეთილის დასამატებლად ჯერ საჭიროა კლასი, საგანი და
                        მინიმუმ ერთი მასწავლებელი (მოწვევა — „წევრები").
                    </p>
                )}

                {showLessonForm && (
                    <form
                        onSubmit={submitLesson}
                        className="mb-4 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    სასწავლო წელი
                                </label>
                                <select
                                    name="academic_year_id"
                                    required
                                    value={yearId ?? ''}
                                    onChange={(event) =>
                                        setYearId(Number(event.target.value))
                                    }
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">აირჩიეთ</option>
                                    {academicYears.map((year) => (
                                        <option key={year.id} value={year.id}>
                                            {year.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    კლასი
                                </label>
                                <select
                                    name="school_class_id"
                                    required
                                    value={classId ?? ''}
                                    onChange={(event) =>
                                        setClassId(
                                            event.target.value
                                                ? Number(event.target.value)
                                                : null,
                                        )
                                    }
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">აირჩიეთ</option>
                                    {schoolClasses.map((schoolClass) => (
                                        <option
                                            key={schoolClass.id}
                                            value={schoolClass.id}
                                        >
                                            {schoolClass.name} ·{' '}
                                            {schoolClass.academicYearName}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    ჯგუფი
                                </label>
                                <select
                                    name="student_group_id"
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">მთელი კლასი</option>
                                    {classGroups.map((group) => (
                                        <option key={group.id} value={group.id}>
                                            {group.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    ცხრილის ვერსია
                                </label>
                                <select
                                    name="timetable_version_id"
                                    key={`version-${yearId}`}
                                    defaultValue={
                                        yearVersions.find(
                                            (v) => v.status === 'active',
                                        )?.id ?? ''
                                    }
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">ვერსიის გარეშე</option>
                                    {yearVersions.map((version) => (
                                        <option
                                            key={version.id}
                                            value={version.id}
                                        >
                                            {version.label} ·{' '}
                                            {VERSION_STATUS[version.status]}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    საგანი
                                </label>
                                <select
                                    name="subject_id"
                                    required
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">აირჩიეთ</option>
                                    {subjects.map((subject) => (
                                        <option
                                            key={subject.id}
                                            value={subject.id}
                                        >
                                            {subject.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    მასწავლებელი
                                </label>
                                <select
                                    name="teacher_id"
                                    required
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">აირჩიეთ</option>
                                    {teachers.map((teacher) => (
                                        <option
                                            key={teacher.id}
                                            value={teacher.id}
                                        >
                                            {teacher.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    ოთახი (არასავალდებულო)
                                </label>
                                <select
                                    name="room_id"
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">—</option>
                                    {rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დღე
                                </label>
                                <select
                                    name="day_of_week"
                                    required
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    {Object.entries(DAY_LABELS).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                            {yearPeriods.length > 0 && (
                                <div>
                                    <label className="mb-1 block text-sm font-medium">
                                        პერიოდი (ზარის მიხედვით)
                                    </label>
                                    <select
                                        name="period_number"
                                        onChange={(event) =>
                                            pickPeriod(event.target.value)
                                        }
                                        className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                    >
                                        <option value="">—</option>
                                        {yearPeriods.map((period) => (
                                            <option
                                                key={period.number}
                                                value={period.number}
                                            >
                                                {period.number}.{' '}
                                                {period.startsAt}–
                                                {period.endsAt}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დაწყების დრო
                                </label>
                                <Input
                                    name="starts_at"
                                    type="time"
                                    required
                                    value={startsAt}
                                    onChange={(event) =>
                                        setStartsAt(event.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დასრულების დრო
                                </label>
                                <Input
                                    name="ends_at"
                                    type="time"
                                    required
                                    value={endsAt}
                                    onChange={(event) =>
                                        setEndsAt(event.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    სტატუსი
                                </label>
                                <select
                                    name="status"
                                    required
                                    defaultValue="published"
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="draft">
                                        {STATUS_LABELS.draft}
                                    </option>
                                    <option value="published">
                                        {STATUS_LABELS.published}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <Button type="submit" disabled={sending}>
                            შენახვა
                        </Button>
                    </form>
                )}

                {lessons.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <CalendarClock className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">
                            გაკვეთილი ჯერ არ არის დამატებული.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {lessons.map((lesson) => (
                            <li
                                key={lesson.id}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {lesson.subjectName} ·{' '}
                                        {lesson.schoolClassName}
                                        {lesson.groupName &&
                                            ` · ${lesson.groupName}`}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {DAY_LABELS[lesson.dayOfWeek]} ·{' '}
                                        {lesson.periodNumber !== null &&
                                            `${lesson.periodNumber}. `}
                                        {lesson.startsAt}–{lesson.endsAt} ·{' '}
                                        {lesson.teacherName}
                                        {lesson.roomName &&
                                            ` · ${lesson.roomName}`}
                                    </p>
                                </div>
                                <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                    {STATUS_LABELS[lesson.status] ??
                                        lesson.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}

/**
 * The year's bell schedule: period number → start/end. It pre-fills a new
 * lesson's times; lessons already saved keep their own.
 */
function BellSchedule({
    yearId,
    periods,
}: {
    yearId: number;
    periods: BellPeriodRow[];
}) {
    const [rows, setRows] = useState(
        periods.map((p) => ({ startsAt: p.startsAt, endsAt: p.endsAt })),
    );
    const [sending, setSending] = useState(false);

    const update = (
        index: number,
        field: 'startsAt' | 'endsAt',
        value: string,
    ) =>
        setRows((current) =>
            current.map((row, i) =>
                i === index ? { ...row, [field]: value } : row,
            ),
        );

    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSending(true);
        router.put(
            TimetableSetupController.saveBellPeriods.url(yearId),
            {
                periods: rows.map((row) => ({
                    starts_at: row.startsAt,
                    ends_at: row.endsAt,
                })),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(Object.values(errors)[0] ?? 'ვერ შეინახა.'),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <section className="mb-8" aria-labelledby="bell-schedule">
            <h2 id="bell-schedule" className="mb-3 text-lg">
                ზარების განრიგი
            </h2>
            <form
                onSubmit={save}
                className="space-y-3 rounded-xl border border-slate-200 bg-white p-4"
            >
                {rows.length === 0 && (
                    <p className="text-sm text-slate-500">
                        ზარები ჯერ არ არის. დაამატეთ პერიოდები — ისინი ახალი
                        გაკვეთილის დროს შეავსებს.
                    </p>
                )}
                <ol className="space-y-2">
                    {rows.map((row, index) => (
                        <li
                            key={index}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <span className="w-8 pb-3 text-sm font-medium">
                                {index + 1}.
                            </span>
                            <label className="text-sm">
                                <span className="mb-1 block text-xs text-slate-600">
                                    დაწყება
                                </span>
                                <Input
                                    type="time"
                                    required
                                    value={row.startsAt}
                                    onChange={(event) =>
                                        update(
                                            index,
                                            'startsAt',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="text-sm">
                                <span className="mb-1 block text-xs text-slate-600">
                                    დასრულება
                                </span>
                                <Input
                                    type="time"
                                    required
                                    value={row.endsAt}
                                    onChange={(event) =>
                                        update(
                                            index,
                                            'endsAt',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label={`${index + 1}-ე პერიოდის წაშლა`}
                                className="h-11 w-11"
                                onClick={() =>
                                    setRows((current) =>
                                        current.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 size={16} />
                            </Button>
                        </li>
                    ))}
                </ol>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={rows.length >= 15}
                        onClick={() =>
                            setRows((current) => [
                                ...current,
                                { startsAt: '', endsAt: '' },
                            ])
                        }
                    >
                        <Plus size={16} /> პერიოდი
                    </Button>
                    <Button type="submit" disabled={sending}>
                        ზარების შენახვა
                    </Button>
                </div>
            </form>
        </section>
    );
}

/**
 * Timetable versions of the year with their validity. Lessons are checked
 * for clashes only within one version; activating a version archives the
 * one active before it.
 */
function Versions({
    yearId,
    versions,
}: {
    yearId: number;
    versions: Version[];
}) {
    const [showForm, setShowForm] = useState(false);
    const [sending, setSending] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        setSending(true);
        router.post(
            TimetableSetupController.storeVersion.url(yearId),
            new FormData(target),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowForm(false);
                    target.reset();
                },
                onError: (errors) =>
                    toast.error(Object.values(errors)[0] ?? 'ვერ შეინახა.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const activate = (version: Version) =>
        router.post(
            TimetableSetupController.activateVersion.url(version.id),
            {},
            { preserveScroll: true },
        );

    return (
        <section className="mb-8" aria-labelledby="timetable-versions">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 id="timetable-versions" className="text-lg">
                    ცხრილის ვერსიები
                </h2>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setShowForm((v) => !v)}
                >
                    <Plus size={16} /> ახალი ვერსია
                </Button>
            </div>
            {showForm && (
                <form
                    onSubmit={submit}
                    className="mb-4 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-2"
                >
                    <div>
                        <label
                            htmlFor="version-label"
                            className="mb-1 block text-sm font-medium"
                        >
                            დასახელება
                        </label>
                        <Input
                            id="version-label"
                            name="label"
                            maxLength={120}
                            placeholder="მაგ. II სემესტრი"
                            required
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="version-source"
                            className="mb-1 block text-sm font-medium"
                        >
                            წყაროს ვერსია (არასავალდებულო)
                        </label>
                        <Input
                            id="version-source"
                            name="source_version"
                            maxLength={64}
                            placeholder="მაგ. 210"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="version-from"
                            className="mb-1 block text-sm font-medium"
                        >
                            მოქმედებს დან
                        </label>
                        <Input
                            id="version-from"
                            name="valid_from"
                            type="date"
                            required
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="version-to"
                            className="mb-1 block text-sm font-medium"
                        >
                            მოქმედებს მდე (არასავალდებულო)
                        </label>
                        <Input id="version-to" name="valid_to" type="date" />
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={sending}>
                            შენახვა
                        </Button>
                    </div>
                </form>
            )}
            {versions.length === 0 ? (
                <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    ვერსია ჯერ არ არის. ვერსიის გარეშე გაკვეთილებიც მუშაობს.
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {versions.map((version) => (
                        <li
                            key={version.id}
                            className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <p className="font-medium">
                                    {version.label}
                                    {version.sourceVersion &&
                                        ` (v${version.sourceVersion})`}
                                </p>
                                <p className="text-sm text-slate-500">
                                    {version.validFrom} –{' '}
                                    {version.validTo ?? '…'} ·{' '}
                                    {VERSION_STATUS[version.status]}
                                </p>
                            </div>
                            {version.status !== 'active' && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => activate(version)}
                                >
                                    გააქტიურება
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
