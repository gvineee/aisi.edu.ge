import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CalendarClock, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as LessonController from '@/actions/App/Http/Controllers/Portal/LessonController';

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
};

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
}: Props) {
    const [showSubjectForm, setShowSubjectForm] = useState(false);
    const [showLessonForm, setShowLessonForm] = useState(false);
    const [sending, setSending] = useState(false);

    const submitSubject = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);

        router.post(LessonController.storeSubject.url(), form, {
            onSuccess: () => {
                toast.success('საგანი დაემატა.');
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
                toast.success('გაკვეთილი დაემატა.');
                setShowLessonForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () =>
                toast.error(
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
                                    defaultValue={
                                        academicYears.find((y) => y.isCurrent)
                                            ?.id ?? ''
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
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დაწყების დრო
                                </label>
                                <Input name="starts_at" type="time" required />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დასრულების დრო
                                </label>
                                <Input name="ends_at" type="time" required />
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
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {DAY_LABELS[lesson.dayOfWeek]} ·{' '}
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
