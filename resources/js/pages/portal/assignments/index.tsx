import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ClipboardList, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Empty,
    EmptyHeader,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';

type TeacherAssignmentOption = { id: number; label: string };

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

type Props = {
    teacherAssignments: TeacherAssignmentOption[];
    assignments: AssignmentSummary[];
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'მონახაზი',
    published: 'გამოქვეყნებულია',
};

function formatDueDate(iso: string | null): string | null {
    if (!iso) return null;

    return new Date(iso).toLocaleDateString('ka-GE', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

export default function AssignmentIndex({
    teacherAssignments,
    assignments,
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [pendingId, setPendingId] = useState<number | null>(null);

    const submitCreate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSaving(true);
        const form = new FormData(event.currentTarget);

        router.post('/portal/assignments', form, {
            forceFormData: true,
            onSuccess: () => {
                setCreateOpen(false);
                toast.success('დავალება შეიქმნა მონახაზის სახით.');
            },
            onError: () => toast.error('ვერ შეინახა — გადაამოწმეთ ველები.'),
            onFinish: () => setSaving(false),
        });
    };

    const togglePublish = (assignment: AssignmentSummary) => {
        setPendingId(assignment.id);
        const url =
            assignment.status === 'published'
                ? `/portal/assignments/${assignment.id}/unpublish`
                : `/portal/assignments/${assignment.id}/publish`;

        router.post(
            url,
            {},
            {
                onSuccess: () =>
                    toast.success(
                        assignment.status === 'published'
                            ? 'დავალება დაბრუნდა მონახაზში.'
                            : 'დავალება გამოქვეყნდა.',
                    ),
                onError: () => toast.error('ვერ შესრულდა.'),
                onFinish: () => setPendingId(null),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="დავალებები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">დავალებები</h1>
                <Button
                    onClick={() => setCreateOpen(true)}
                    disabled={teacherAssignments.length === 0}
                >
                    <Plus size={18} /> ახალი დავალება
                </Button>
            </div>

            {teacherAssignments.length === 0 && (
                <p className="mb-6 text-sm text-slate-500">
                    დავალების შესაქმნელად საჭიროა მიბმული კლასი/საგანი — ჯერ
                    არცერთი არ არის.
                </p>
            )}

            {assignments.length === 0 ? (
                <Empty className="rounded-xl border border-slate-200 bg-white p-8">
                    <EmptyHeader>
                        <ClipboardList />
                        <EmptyTitle>ჯერ არცერთი დავალება არ არის</EmptyTitle>
                        <EmptyDescription>
                            შექმენით პირველი დავალება „ახალი დავალება"
                            ღილაკით.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {assignments.map((assignment) => (
                        <li
                            key={assignment.id}
                            className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <Link
                                href={`/portal/assignments/${assignment.id}/submissions`}
                                className="min-w-0 flex-1"
                            >
                                <p className="font-medium">
                                    {assignment.title}
                                </p>
                                <p className="text-sm text-slate-500">
                                    {[assignment.className, assignment.subject]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    {assignment.dueAt &&
                                        ` · ვადა: ${formatDueDate(assignment.dueAt)}`}
                                </p>
                                <p className="text-sm text-slate-500">
                                    გაგზავნილია: {assignment.submissionsCount}
                                </p>
                            </Link>
                            <div className="flex items-center gap-2">
                                <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                                    {STATUS_LABELS[assignment.status] ??
                                        assignment.status}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pendingId === assignment.id}
                                    onClick={() => togglePublish(assignment)}
                                >
                                    {assignment.status === 'published'
                                        ? 'მონახაზში დაბრუნება'
                                        : 'გამოქვეყნება'}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <DialogTitle>ახალი დავალება</DialogTitle>
                    <DialogDescription>
                        აირჩიეთ კლასი/საგანი, სათაური და, სურვილისამებრ, ვადა
                        და მაქსიმალური ქულა.
                    </DialogDescription>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="assignment-teacher-assignment">
                                კლასი / საგანი
                            </Label>
                            <select
                                id="assignment-teacher-assignment"
                                name="teacher_assignment_id"
                                required
                                className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                            >
                                {teacherAssignments.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="assignment-title">სათაური</Label>
                            <input
                                id="assignment-title"
                                name="title"
                                required
                                maxLength={255}
                                className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="assignment-description">
                                აღწერა
                            </Label>
                            <textarea
                                id="assignment-description"
                                name="description"
                                className="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="assignment-due-at">
                                    ვადა (არასავალდებულო)
                                </Label>
                                <input
                                    id="assignment-due-at"
                                    type="datetime-local"
                                    name="due_at"
                                    className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="assignment-max-score">
                                    მაქს. ქულა (არასავალდებულო)
                                </Label>
                                <input
                                    id="assignment-max-score"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    name="max_score"
                                    className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                                />
                            </div>
                        </div>
                        <Button
                            type="submit"
                            disabled={saving}
                            className="w-full"
                        >
                            შენახვა (მონახაზად)
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </PortalLayout>
    );
}
