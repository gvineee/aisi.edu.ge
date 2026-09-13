import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FileCheck2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';

type AssignmentSummary = {
    id: number;
    title: string;
    description: string | null;
    className: string | null;
    subject: string | null;
    status: string;
    dueAt: string | null;
    maxScore: number | null;
    submissionsCount: number;
    updatedAt: string | null;
};

type RosterRow = {
    studentId: number;
    studentName: string;
    submissionId: number | null;
    status: string;
    isLate: boolean;
    submittedAt: string | null;
    textResponse: string | null;
    downloadUrl: string | null;
    score: number | null;
    feedback: string | null;
};

type Props = {
    assignment: AssignmentSummary;
    roster: RosterRow[];
};

const STATUS_LABELS: Record<string, string> = {
    not_submitted: 'არ გაუგზავნია',
    submitted: 'გაგზავნილია',
    graded: 'შეფასებულია',
    returned: 'დაბრუნებულია',
};

function GradeRow({
    assignment,
    row,
}: {
    assignment: AssignmentSummary;
    row: RosterRow;
}) {
    const [score, setScore] = useState(row.score?.toString() ?? '');
    const [feedback, setFeedback] = useState(row.feedback ?? '');
    const [grading, setGrading] = useState(false);

    const submitGrade = (event: FormEvent) => {
        event.preventDefault();
        if (!row.submissionId) return;

        setGrading(true);
        router.post(
            `/portal/assignments/${assignment.id}/submissions/${row.submissionId}/grade`,
            { score, feedback },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('შეფასდა.'),
                onError: () => toast.error('ვერ შეფასდა — გადაამოწმეთ ქულა.'),
                onFinish: () => setGrading(false),
            },
        );
    };

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-medium">{row.studentName}</p>
                    <p className="text-sm text-slate-500">
                        {STATUS_LABELS[row.status] ?? row.status}
                        {row.isLate && (
                            <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                დაგვიანებული
                            </span>
                        )}
                    </p>
                </div>
                {row.downloadUrl && (
                    <a
                        href={row.downloadUrl}
                        className="flex items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100"
                    >
                        <FileCheck2 size={16} /> ფაილის ნახვა
                    </a>
                )}
            </div>

            {row.textResponse && (
                <p className="rounded-lg bg-slate-50 p-3 text-sm whitespace-pre-wrap">
                    {row.textResponse}
                </p>
            )}

            {row.submissionId && (
                <form
                    onSubmit={submitGrade}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="space-y-1">
                        <label className="block text-xs font-medium text-slate-600">
                            ქულა
                            {assignment.maxScore
                                ? ` (მაქს. ${assignment.maxScore})`
                                : ''}
                        </label>
                        <input
                            type="number"
                            min={0}
                            max={assignment.maxScore ?? undefined}
                            value={score}
                            onChange={(event) => setScore(event.target.value)}
                            required
                            className="h-9 w-24 rounded-md border border-slate-300 px-2 text-sm"
                        />
                    </div>
                    <div className="min-w-[200px] flex-1 space-y-1">
                        <label className="block text-xs font-medium text-slate-600">
                            უკუკავშირი (არასავალდებულო)
                        </label>
                        <input
                            value={feedback}
                            onChange={(event) =>
                                setFeedback(event.target.value)
                            }
                            className="h-9 w-full rounded-md border border-slate-300 px-2 text-sm"
                        />
                    </div>
                    <Button type="submit" size="sm" disabled={grading}>
                        {row.status === 'graded' ? 'ხელახლა შეფასება' : 'შეფასება'}
                    </Button>
                </form>
            )}
        </li>
    );
}

export default function AssignmentSubmissions({ assignment, roster }: Props) {
    return (
        <PortalLayout>
            <Head title={`${assignment.title} — გაგზავნილები`} />

            <div className="mb-6">
                <h1 className="text-2xl">{assignment.title}</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {[assignment.className, assignment.subject]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>

            {roster.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <p className="font-medium">
                        ამ კლასში აქტიური მოსწავლე არ არის.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {roster.map((row) => (
                        <GradeRow
                            key={row.studentId}
                            assignment={assignment}
                            row={row}
                        />
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
