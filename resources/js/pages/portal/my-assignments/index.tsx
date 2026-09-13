import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ClipboardList } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyHeader,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';

type AssignmentItem = {
    id: number;
    title: string;
    description: string | null;
    subject: string | null;
    dueAt: string | null;
    maxScore: number | null;
    status: string;
    isLate: boolean;
    score: number | null;
    feedback: string | null;
    canSubmit: boolean;
};

type Props = { assignments: AssignmentItem[] };

const STATUS_LABELS: Record<string, string> = {
    not_submitted: 'გასაგზავნია',
    submitted: 'გაგზავნილია',
    graded: 'შეფასებულია',
    returned: 'დაბრუნებულია',
};

function formatDueDate(iso: string | null): string | null {
    if (!iso) return null;

    return new Date(iso).toLocaleDateString('ka-GE', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function AssignmentCard({ assignment }: { assignment: AssignmentItem }) {
    const [expanded, setExpanded] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [textResponse, setTextResponse] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSubmitting(true);

        const data = new FormData();
        if (file) data.append('file', file);
        if (textResponse) data.append('text_response', textResponse);

        router.post(`/portal/my-assignments/${assignment.id}/submit`, data, {
            forceFormData: true,
            onSuccess: () => {
                setExpanded(false);
                setFile(null);
                setTextResponse('');
                toast.success('დავალება გაიგზავნა.');
            },
            onError: () =>
                toast.error('ვერ გაიგზავნა — ატვირთეთ ფაილი ან ტექსტი.'),
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    {assignment.subject && (
                        <p className="mb-1 text-xs font-semibold text-slate-500">
                            {assignment.subject}
                        </p>
                    )}
                    <p className="font-medium">{assignment.title}</p>
                    {assignment.dueAt && (
                        <p className="text-sm text-slate-500">
                            ვადა: {formatDueDate(assignment.dueAt)}
                        </p>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {assignment.isLate && (
                        <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">
                            დაგვიანებული
                        </span>
                    )}
                    <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                        {STATUS_LABELS[assignment.status] ??
                            assignment.status}
                    </span>
                </div>
            </div>

            {assignment.description && (
                <p className="text-sm text-slate-700">
                    {assignment.description}
                </p>
            )}

            {assignment.status === 'graded' && (
                <div className="rounded-lg bg-slate-50 p-3 text-sm">
                    <p className="font-medium">
                        ქულა: {assignment.score}
                        {assignment.maxScore ? ` / ${assignment.maxScore}` : ''}
                    </p>
                    {assignment.feedback && (
                        <p className="mt-1 text-slate-600">
                            {assignment.feedback}
                        </p>
                    )}
                </div>
            )}

            {assignment.canSubmit && (
                <div>
                    {!expanded ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setExpanded(true)}
                        >
                            {assignment.status === 'not_submitted'
                                ? 'გაგზავნა'
                                : 'თავიდან გაგზავნა'}
                        </Button>
                    ) : (
                        <form onSubmit={submit} className="space-y-3">
                            <textarea
                                value={textResponse}
                                onChange={(event) =>
                                    setTextResponse(event.target.value)
                                }
                                placeholder="პასუხის ტექსტი (არასავალდებულო, თუ ფაილს ტვირთავთ)"
                                className="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            />
                            <input
                                type="file"
                                onChange={(event) =>
                                    setFile(event.target.files?.[0] ?? null)
                                }
                                className="text-sm"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={submitting}
                                >
                                    გაგზავნა
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setExpanded(false)}
                                >
                                    გაუქმება
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            )}
        </li>
    );
}

export default function MyAssignmentsIndex({ assignments }: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი დავალებები" />

            <h1 className="mb-6 text-2xl">ჩემი დავალებები</h1>

            {assignments.length === 0 ? (
                <Empty className="rounded-xl border border-slate-200 bg-white p-8">
                    <EmptyHeader>
                        <ClipboardList />
                        <EmptyTitle>ამჟამად დავალება არ არის</EmptyTitle>
                        <EmptyDescription>
                            გამოქვეყნებული დავალებები აქ გამოჩნდება.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {assignments.map((assignment) => (
                        <AssignmentCard
                            key={assignment.id}
                            assignment={assignment}
                        />
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
