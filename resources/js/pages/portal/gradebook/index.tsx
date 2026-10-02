import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ClipboardList, Lock, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AssessmentStatusBadge from '@/components/portal/assessment-status-badge';
import { firstError, formatDay, formatScore } from '@/lib/assessment';

type Option = { id: number; label: string };

type Period = {
    id: number;
    name: string;
    startsOn: string;
    endsOn: string;
    isClosed: boolean;
};

type AssessmentRow = {
    id: number;
    title: string;
    assessedOn: string;
    categoryName: string | null;
    maxScore: string;
    status: string;
    resultsCount: number;
};

type Props = {
    teacherAssignments: Option[];
    selectedTeacherAssignmentId: number | null;
    periods: Period[];
    selectedPeriodId: number | null;
    categories: { id: number; name: string }[];
    rosterCount: number;
    assessments: AssessmentRow[];
};

const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

/**
 * Teacher gradebook overview: pick one of your own class/subject rows and a
 * grading period, see its assessments, and add a new one. Scores are
 * entered on the assessment's own page.
 */
export default function GradebookIndex({
    teacherAssignments,
    selectedTeacherAssignmentId,
    periods,
    selectedPeriodId,
    categories,
    rosterCount,
    assessments,
}: Props) {
    const [showForm, setShowForm] = useState(false);
    const [sending, setSending] = useState(false);
    const selectedPeriod = periods.find((p) => p.id === selectedPeriodId);

    const navigate = (ta: number | null, period: number | null) => {
        router.get(
            '/portal/gradebook',
            { ta: ta ?? undefined, period: period ?? undefined },
            { preserveState: false },
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post('/portal/gradebook/assessments', form, {
            onError: (errors) =>
                toast.error(firstError(errors) ?? 'შეამოწმეთ ველები.'),
            onFinish: () => setSending(false),
        });
    };

    if (teacherAssignments.length === 0) {
        return (
            <PortalLayout>
                <Head title="შეფასებები" />
                <h1 className="mb-6 text-2xl">შეფასებები</h1>
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <ClipboardList className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        თქვენთვის კლასი ან საგანი ჯერ არ არის მინიჭებული.
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        მიმართეთ სკოლის ადმინისტრაციას.
                    </p>
                </div>
            </PortalLayout>
        );
    }

    return (
        <PortalLayout>
            <Head title="შეფასებები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">შეფასებები</h1>
                {selectedPeriod && !selectedPeriod.isClosed && (
                    <Button
                        variant="outline"
                        onClick={() => setShowForm((v) => !v)}
                        aria-expanded={showForm}
                    >
                        <Plus size={16} /> ახალი შეფასება
                    </Button>
                )}
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-2">
                <label className="block text-sm font-medium">
                    კლასი და საგანი
                    <select
                        className={`${selectClass} mt-1`}
                        value={selectedTeacherAssignmentId ?? ''}
                        onChange={(e) => navigate(Number(e.target.value), null)}
                    >
                        {teacherAssignments.map((ta) => (
                            <option key={ta.id} value={ta.id}>
                                {ta.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block text-sm font-medium">
                    შეფასების პერიოდი
                    <select
                        className={`${selectClass} mt-1`}
                        value={selectedPeriodId ?? ''}
                        disabled={periods.length === 0}
                        onChange={(e) =>
                            navigate(
                                selectedTeacherAssignmentId,
                                Number(e.target.value),
                            )
                        }
                    >
                        {periods.length === 0 && (
                            <option value="">პერიოდი არ არის</option>
                        )}
                        {periods.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                                {p.isClosed ? ' (დახურული)' : ''}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            {periods.length === 0 && (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <p className="font-medium">
                        ამ სასწავლო წლისთვის შეფასების პერიოდი ჯერ არ შექმნილა.
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        პერიოდებს ქმნის აკადემიური მენეჯერი ან დირექცია
                        („შეფასების წესები“).
                    </p>
                </div>
            )}

            {selectedPeriod?.isClosed && (
                <p className="mb-4 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <Lock size={16} aria-hidden /> ეს პერიოდი დახურულია —
                    ქულების შეცვლა და ახალი შეფასების დამატება შეუძლებელია.
                </p>
            )}

            {showForm && selectedPeriod && (
                <form
                    onSubmit={submit}
                    className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <input
                        type="hidden"
                        name="teacher_assignment_id"
                        value={selectedTeacherAssignmentId ?? ''}
                    />
                    <input
                        type="hidden"
                        name="assessment_period_id"
                        value={selectedPeriod.id}
                    />
                    <div>
                        <label
                            htmlFor="assessment-title"
                            className="mb-1 block text-sm font-medium"
                        >
                            დასახელება
                        </label>
                        <Input
                            id="assessment-title"
                            name="title"
                            required
                            maxLength={255}
                            placeholder="მაგ. წილადები — შემაჯამებელი"
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label
                                htmlFor="assessment-date"
                                className="mb-1 block text-sm font-medium"
                            >
                                თარიღი
                            </label>
                            <Input
                                id="assessment-date"
                                name="assessed_on"
                                type="date"
                                required
                                min={selectedPeriod.startsOn}
                                max={selectedPeriod.endsOn}
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="assessment-max"
                                className="mb-1 block text-sm font-medium"
                            >
                                მაქსიმალური ქულა
                            </label>
                            <Input
                                id="assessment-max"
                                name="max_score"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0.01"
                                required
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="assessment-category"
                                className="mb-1 block text-sm font-medium"
                            >
                                კატეგორია
                            </label>
                            <select
                                id="assessment-category"
                                name="assessment_category_id"
                                className={selectClass}
                                defaultValue=""
                            >
                                <option value="">—</option>
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <Button type="submit" disabled={sending}>
                        შექმნა და ქულების შეყვანა
                    </Button>
                </form>
            )}

            {selectedPeriod && assessments.length === 0 && (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <p className="font-medium">
                        ამ პერიოდში შეფასება ჯერ არ არის.
                    </p>
                </div>
            )}

            {assessments.length > 0 && (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {assessments.map((a) => (
                        <li key={a.id}>
                            <Link
                                href={`/portal/gradebook/assessments/${a.id}`}
                                className="flex flex-col gap-1 p-4 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium break-words">
                                        {a.title}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {formatDay(a.assessedOn)}
                                        {a.categoryName
                                            ? ` · ${a.categoryName}`
                                            : ''}{' '}
                                        · მაქს. {formatScore(a.maxScore)}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2 text-sm">
                                    <span className="text-slate-500">
                                        {a.resultsCount}/{rosterCount} შეყვანილი
                                    </span>
                                    <AssessmentStatusBadge status={a.status} />
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
