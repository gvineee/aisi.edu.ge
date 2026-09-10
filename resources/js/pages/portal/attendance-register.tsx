import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { store as storeAttendance } from '@/actions/App/Http/Controllers/Portal/AttendanceController';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toast } from 'sonner';

type Student = {
    id: number;
    name: string;
    status: string | null;
    comment: string | null;
};

type Props = {
    lesson: {
        id: number;
        subject: string;
        className: string;
    };
    date: string;
    students: Student[];
};

const statusOptions: Array<{ value: string; label: string }> = [
    { value: 'present', label: 'ესწრება' },
    { value: 'late', label: 'აგვიანებს' },
    { value: 'absent', label: 'არ ესწრება' },
    { value: 'excused', label: 'საპატიო' },
];

/**
 * Real, server-authorized attendance taking — the teacher this lesson
 * belongs to only (AttendanceController checks it explicitly). Submitting
 * again for the same date updates the same records, it never duplicates
 * them (docs/02 §5.4).
 */
export default function AttendanceRegister({ lesson, date, students }: Props) {
    const { errors } = usePage().props;
    const [values, setValues] = useState<Record<number, string | null>>(
        Object.fromEntries(students.map((s) => [s.id, s.status])),
    );
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            storeAttendance.url({ lesson: lesson.id }),
            {
                occurred_on: date,
                records: students
                    .filter((s) => values[s.id])
                    .map((s) => ({ student_id: s.id, status: values[s.id] })),
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('დასწრება შენახულია.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="დასწრება" />

            <h1 className="mb-1 text-2xl">
                {lesson.subject} · {lesson.className}
            </h1>
            <p className="mb-8 text-sm text-slate-500">
                {new Date(date).toLocaleDateString('ka-GE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                })}
            </p>

            {errors?.records && (
                <p className="mb-4 text-sm text-red-600">{errors.records}</p>
            )}

            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                {students.map((student) => (
                    <li
                        key={student.id}
                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span className="font-medium">{student.name}</span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={values[student.id] ?? undefined}
                            onValueChange={(value) =>
                                setValues((prev) => ({
                                    ...prev,
                                    [student.id]: value || null,
                                }))
                            }
                            aria-label={`${student.name} დასწრება`}
                        >
                            {statusOptions.map((option) => (
                                <ToggleGroupItem
                                    key={option.value}
                                    value={option.value}
                                    className="min-h-11 px-3 text-xs"
                                >
                                    {option.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </li>
                ))}
            </ul>

            <Button className="mt-6" onClick={submit} disabled={processing}>
                შენახვა
            </Button>
        </PortalLayout>
    );
}
