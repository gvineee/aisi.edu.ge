import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Plus, Repeat } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type TeacherOption = {
    id: number;
    name: string;
};

type AbsenceRow = {
    id: number;
    teacherId: number;
    teacherName: string;
    startsOn: string;
    endsOn: string;
    reason: string | null;
    status: string;
};

type CoverageRow = {
    lessonId: number;
    absenceId: number;
    date: string;
    subject: string;
    className: string;
    startsAt: string;
    endsAt: string;
    absentTeacherId: number;
    absentTeacherName: string;
};

type AssignmentRow = {
    id: number;
    lessonId: number;
    subject: string;
    className: string;
    date: string;
    startsAt: string;
    endsAt: string;
    absentTeacherName: string;
    substituteTeacherName: string;
    status: string;
};

type Props = {
    teachers: TeacherOption[];
    absences: AbsenceRow[];
    coverageNeeded: CoverageRow[];
    assignments: AssignmentRow[];
};

const ABSENCE_STATUS_LABELS: Record<string, string> = {
    reported: 'დაფიქსირებულია',
    covered: 'დაფარულია',
};

const ASSIGNMENT_STATUS_LABELS: Record<string, string> = {
    assigned: 'დანიშნულია',
    cancelled: 'გაუქმებულია',
};

/**
 * "ჩანაცვლებები" — admin/academic_manager/director report a teacher's
 * absence, then assign a substitute per affected lesson+date from the
 * "საჭიროებს შემცვლელს" worklist. The substitute teacher then sees the
 * coverage on their own dashboard (teacher-dashboard.tsx), not here.
 */
export default function SubstitutionsIndex({
    teachers,
    absences,
    coverageNeeded,
    assignments,
}: Props) {
    const [showAbsenceForm, setShowAbsenceForm] = useState(false);
    const [sending, setSending] = useState(false);
    const [assigningKey, setAssigningKey] = useState<string | null>(null);

    const submitAbsence = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);

        router.post('/portal/substitutions/absences', form, {
            onSuccess: () => {
                toast.success('გაცდენა დაფიქსირდა.');
                setShowAbsenceForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSending(false),
        });
    };

    const submitAssign = (
        event: FormEvent<HTMLFormElement>,
        row: CoverageRow,
    ) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        form.set('lesson_id', String(row.lessonId));
        form.set('absence_id', String(row.absenceId));
        form.set('date', row.date);
        const key = `${row.lessonId}-${row.date}`;
        setAssigningKey(key);

        router.post('/portal/substitutions/assign', form, {
            onSuccess: () => toast.success('შემცვლელი დაინიშნა.'),
            onError: () =>
                toast.error(
                    'ვერ დაინიშნა — შემცვლელს ამ დროს უკვე აქვს გაკვეთილი.',
                ),
            onFinish: () => setAssigningKey(null),
        });
    };

    const cancelAssignment = (assignment: AssignmentRow) => {
        router.post(
            `/portal/substitutions/${assignment.id}/cancel`,
            {},
            { onSuccess: () => toast.success('ჩანაცვლება გაუქმდა.') },
        );
    };

    return (
        <PortalLayout>
            <Head title="ჩანაცვლებები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ჩანაცვლებები</h1>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setShowAbsenceForm((v) => !v)}
                    disabled={teachers.length === 0}
                >
                    <Plus size={16} /> გაცდენის დაფიქსირება
                </Button>
            </div>

            {teachers.length === 0 && (
                <p className="mb-4 text-sm text-slate-500">
                    გაცდენის დასაფიქსირებლად ჯერ საჭიროა მინიმუმ ერთი
                    მასწავლებელი.
                </p>
            )}

            {showAbsenceForm && (
                <form
                    onSubmit={submitAbsence}
                    className="mb-8 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            მასწავლებელი
                        </label>
                        <select
                            name="user_id"
                            required
                            className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                        >
                            <option value="">აირჩიეთ მასწავლებელი</option>
                            {teachers.map((teacher) => (
                                <option key={teacher.id} value={teacher.id}>
                                    {teacher.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                დაწყების თარიღი
                            </label>
                            <Input name="starts_on" type="date" required />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                დასრულების თარიღი
                            </label>
                            <Input name="ends_on" type="date" required />
                        </div>
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            მიზეზი (არასავალდებულო)
                        </label>
                        <Input name="reason" />
                    </div>
                    <Button type="submit" disabled={sending}>
                        შენახვა
                    </Button>
                </form>
            )}

            <section className="mb-8">
                <h2 className="mb-3 text-lg">დაფიქსირებული გაცდენები</h2>
                {absences.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <p className="font-medium">
                            გაცდენა ჯერ არ დაფიქსირებულა.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {absences.map((absence) => (
                            <li
                                key={absence.id}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {absence.teacherName}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {absence.startsOn} – {absence.endsOn}
                                        {absence.reason &&
                                            ` · ${absence.reason}`}
                                    </p>
                                </div>
                                <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                    {ABSENCE_STATUS_LABELS[absence.status] ??
                                        absence.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mb-8">
                <h2 className="mb-3 text-lg">საჭიროებს შემცვლელს</h2>
                {coverageNeeded.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <Repeat className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">
                            ყველა გაცდენილი გაკვეთილი დაფარულია.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {coverageNeeded.map((row) => {
                            const key = `${row.lessonId}-${row.date}`;
                            const candidates = teachers.filter(
                                (teacher) => teacher.id !== row.absentTeacherId,
                            );

                            return (
                                <li
                                    key={key}
                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {row.subject} · {row.className}
                                        </p>
                                        <p className="text-sm text-slate-500">
                                            {row.date} · {row.startsAt}–
                                            {row.endsAt} ·{' '}
                                            {row.absentTeacherName}-ს
                                            ნაცვლად
                                        </p>
                                    </div>
                                    <form
                                        onSubmit={(event) =>
                                            submitAssign(event, row)
                                        }
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <select
                                            name="substitute_teacher_id"
                                            required
                                            className="h-9 rounded-md border border-slate-300 px-3 text-sm"
                                        >
                                            <option value="">
                                                შემცვლელი...
                                            </option>
                                            {candidates.map((teacher) => (
                                                <option
                                                    key={teacher.id}
                                                    value={teacher.id}
                                                >
                                                    {teacher.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={assigningKey === key}
                                        >
                                            დანიშვნა
                                        </Button>
                                    </form>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            <section>
                <h2 className="mb-3 text-lg">დანიშნული ჩანაცვლებები</h2>
                {assignments.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <p className="font-medium">
                            ჩანაცვლება ჯერ არ დანიშნულა.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {assignments.map((assignment) => (
                            <li
                                key={assignment.id}
                                className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {assignment.subject} ·{' '}
                                        {assignment.className}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {assignment.date} ·{' '}
                                        {assignment.startsAt}–
                                        {assignment.endsAt} ·{' '}
                                        {assignment.substituteTeacherName}{' '}
                                        ჩაანაცვლებს{' '}
                                        {assignment.absentTeacherName}-ს
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                        {ASSIGNMENT_STATUS_LABELS[
                                            assignment.status
                                        ] ?? assignment.status}
                                    </span>
                                    {assignment.status === 'assigned' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                cancelAssignment(assignment)
                                            }
                                        >
                                            გაუქმება
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
