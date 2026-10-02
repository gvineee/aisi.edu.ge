import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useRef, useState, type FormEvent } from 'react';
import { ArrowLeft, History, Lock } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import AssessmentStatusBadge from '@/components/portal/assessment-status-badge';
import {
    formatDateTime,
    formatDay,
    formatPercent,
    formatScore,
} from '@/lib/assessment';

type Revision = {
    version: number;
    rawScore: string;
    reason: string | null;
    createdAt: string | null;
};

type RosterRow = {
    studentId: number;
    studentName: string;
    rawScore: string | null;
    normalizedPercent: string | null;
    finalGrade: string | null;
    comment: string | null;
    version: number | null;
    revisions: Revision[];
};

type Props = {
    assessment: {
        id: number;
        title: string;
        teacherAssignmentId: number;
        classLabel: string;
        periodId: number;
        periodName: string;
        periodClosed: boolean;
        categoryName: string | null;
        assessedOn: string;
        maxScore: string;
        status: string;
        publishedAt: string | null;
        gradeScale: { name: string; version: number } | null;
    };
    roster: RosterRow[];
};

type Draft = { score: string; comment: string };

function initialDraft(row: RosterRow): Draft {
    return {
        score: row.rawScore === null ? '' : formatScore(row.rawScore),
        comment: row.comment ?? '',
    };
}

/**
 * Score entry for one assessment. Only rows the teacher actually changed
 * are sent; changing an already-saved score requires a reason, and every
 * row carries the version it was loaded at, so a concurrent edit is
 * rejected by the server instead of being silently overwritten.
 */
export default function GradebookShow({ assessment, roster }: Props) {
    const [drafts, setDrafts] = useState<Record<number, Draft>>(() =>
        Object.fromEntries(roster.map((r) => [r.studentId, initialDraft(r)])),
    );
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [confirmPublish, setConfirmPublish] = useState(false);
    const summaryRef = useRef<HTMLDivElement>(null);
    const locked = assessment.periodClosed;

    const changedRows = useMemo(
        () =>
            roster.filter((row) => {
                const draft = drafts[row.studentId];
                const initial = initialDraft(row);

                return (
                    draft.score !== '' &&
                    (draft.score !== initial.score ||
                        draft.comment.trim() !== initial.comment.trim())
                );
            }),
        [drafts, roster],
    );
    const needsReason = changedRows.some((row) => row.version !== null);

    const update = (studentId: number, patch: Partial<Draft>) =>
        setDrafts((current) => ({
            ...current,
            [studentId]: { ...current[studentId], ...patch },
        }));

    const save = (event: FormEvent) => {
        event.preventDefault();
        if (changedRows.length === 0) return;

        setSaving(true);
        router.post(
            `/portal/gradebook/assessments/${assessment.id}/results`,
            {
                results: changedRows.map((row) => ({
                    student_id: row.studentId,
                    raw_score: drafts[row.studentId].score,
                    comment: drafts[row.studentId].comment,
                    expected_version: row.version,
                })),
                reason: needsReason ? reason : '',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setErrors({});
                    setReason('');
                    toast.success('ქულები შენახულია.');
                },
                onError: (errs) => {
                    setErrors(errs);
                    requestAnimationFrame(() => summaryRef.current?.focus());
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    // Re-sync local drafts once the server returns the saved versions.
    const rosterKey = roster
        .map((r) => `${r.studentId}:${r.version ?? 0}`)
        .join('|');
    const [syncedKey, setSyncedKey] = useState(rosterKey);
    if (syncedKey !== rosterKey) {
        setSyncedKey(rosterKey);
        setDrafts(
            Object.fromEntries(
                roster.map((r) => [r.studentId, initialDraft(r)]),
            ),
        );
    }

    const publish = () => {
        router.post(
            `/portal/gradebook/assessments/${assessment.id}/publish`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setConfirmPublish(false);
                    toast.success('შეფასება გამოქვეყნდა.');
                },
                onError: (errs) =>
                    toast.error(Object.values(errs)[0] ?? 'ვერ გამოქვეყნდა.'),
            },
        );
    };

    const rowError = (index: number) =>
        errors[`results.${index}.raw_score`] ??
        errors[`results.${index}.student_id`];

    return (
        <PortalLayout>
            <Head title={`${assessment.title} — შეფასება`} />

            <Link
                href={`/portal/gradebook?ta=${assessment.teacherAssignmentId}&period=${assessment.periodId}`}
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> შეფასებების სია
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-2xl break-words">{assessment.title}</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        {assessment.classLabel} · {assessment.periodName} ·{' '}
                        {formatDay(assessment.assessedOn)}
                        {assessment.categoryName
                            ? ` · ${assessment.categoryName}`
                            : ''}
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        მაქსიმალური ქულა: {formatScore(assessment.maxScore)} ·{' '}
                        {assessment.gradeScale
                            ? `სკალა: ${assessment.gradeScale.name} (ვ.${assessment.gradeScale.version})`
                            : 'შეფასების სკალა სკოლას ჯერ არ დაუმტკიცებია — საბოლოო ნიშანი არ ითვლება'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <AssessmentStatusBadge status={assessment.status} />
                    {assessment.status !== 'published' && !locked && (
                        <>
                            {confirmPublish ? (
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm">
                                        მოსწავლეები და მშობლები დაინახავენ
                                        ქულებს.
                                    </span>
                                    <Button size="sm" onClick={publish}>
                                        დადასტურება
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setConfirmPublish(false)}
                                    >
                                        გაუქმება
                                    </Button>
                                </span>
                            ) : (
                                <Button
                                    size="sm"
                                    onClick={() => setConfirmPublish(true)}
                                >
                                    გამოქვეყნება
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </div>

            {locked && (
                <p className="mb-4 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <Lock size={16} aria-hidden /> პერიოდი დახურულია — ქულები
                    მხოლოდ სანახავადაა.
                </p>
            )}

            {Object.keys(errors).length > 0 && (
                <div
                    ref={summaryRef}
                    tabIndex={-1}
                    role="alert"
                    className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 focus:outline-none"
                >
                    <p className="font-medium">ქულები ვერ შეინახა:</p>
                    <ul className="mt-1 list-disc pl-5">
                        {[...new Set(Object.values(errors))].map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </div>
            )}

            {roster.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <p className="font-medium">
                        ამ კლასში აქტიური მოსწავლე არ არის.
                    </p>
                </div>
            ) : (
                <form onSubmit={save}>
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {roster.map((row) => {
                            const draft = drafts[row.studentId];
                            const changedIndex = changedRows.findIndex(
                                (r) => r.studentId === row.studentId,
                            );
                            const error =
                                changedIndex >= 0
                                    ? rowError(changedIndex)
                                    : undefined;
                            const scoreId = `score-${row.studentId}`;
                            const commentId = `comment-${row.studentId}`;

                            return (
                                <li
                                    key={row.studentId}
                                    className="grid gap-3 p-4 md:grid-cols-[minmax(0,1.2fr)_8rem_minmax(0,1.5fr)_7rem] md:items-end"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">
                                            {row.studentName}
                                        </p>
                                        {row.version !== null && (
                                            <p className="text-xs text-slate-500">
                                                შენახული:{' '}
                                                {formatScore(row.rawScore)} ·{' '}
                                                {formatPercent(
                                                    row.normalizedPercent,
                                                )}
                                                {row.finalGrade
                                                    ? ` · ${row.finalGrade}`
                                                    : ''}{' '}
                                                · ვ.{row.version}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <label
                                            htmlFor={scoreId}
                                            className="mb-1 block text-xs font-medium text-slate-600"
                                        >
                                            ქულა (მაქს.{' '}
                                            {formatScore(assessment.maxScore)})
                                        </label>
                                        <input
                                            id={scoreId}
                                            type="number"
                                            inputMode="decimal"
                                            step="0.01"
                                            min={0}
                                            max={Number(assessment.maxScore)}
                                            value={draft.score}
                                            disabled={locked}
                                            aria-invalid={
                                                error ? true : undefined
                                            }
                                            aria-describedby={
                                                error
                                                    ? `${scoreId}-error`
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                update(row.studentId, {
                                                    score: e.target.value,
                                                })
                                            }
                                            className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm disabled:bg-slate-50"
                                        />
                                        {error && (
                                            <p
                                                id={`${scoreId}-error`}
                                                className="mt-1 text-xs text-red-700"
                                            >
                                                {error}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <label
                                            htmlFor={commentId}
                                            className="mb-1 block text-xs font-medium text-slate-600"
                                        >
                                            კომენტარი (არასავალდებულო)
                                        </label>
                                        <input
                                            id={commentId}
                                            value={draft.comment}
                                            maxLength={1000}
                                            disabled={locked}
                                            onChange={(e) =>
                                                update(row.studentId, {
                                                    comment: e.target.value,
                                                })
                                            }
                                            className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm disabled:bg-slate-50"
                                        />
                                    </div>
                                    <div>
                                        {row.revisions.length > 1 && (
                                            <details className="text-xs">
                                                <summary className="flex min-h-11 cursor-pointer items-center gap-1 text-slate-600">
                                                    <History
                                                        size={14}
                                                        aria-hidden
                                                    />{' '}
                                                    ისტორია
                                                </summary>
                                                <ul className="mt-1 space-y-1">
                                                    {row.revisions.map(
                                                        (rev) => (
                                                            <li
                                                                key={
                                                                    rev.version
                                                                }
                                                            >
                                                                ვ.{rev.version}:{' '}
                                                                {formatScore(
                                                                    rev.rawScore,
                                                                )}
                                                                {rev.createdAt
                                                                    ? ` · ${formatDateTime(rev.createdAt)}`
                                                                    : ''}
                                                                {rev.reason
                                                                    ? ` — ${rev.reason}`
                                                                    : ''}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </details>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    {!locked && (
                        <div className="sticky bottom-20 mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:bottom-4">
                            {needsReason && (
                                <div>
                                    <label
                                        htmlFor="change-reason"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        შეცვლის მიზეზი (სავალდებულო)
                                    </label>
                                    <input
                                        id="change-reason"
                                        value={reason}
                                        required
                                        maxLength={500}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        aria-invalid={
                                            errors.reason ? true : undefined
                                        }
                                        className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                                        placeholder="მაგ. ნაშრომის ხელახალი გადამოწმება"
                                    />
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm text-slate-600">
                                    {changedRows.length === 0
                                        ? 'ცვლილება არ არის.'
                                        : `შესანახია: ${changedRows.length}`}
                                </p>
                                <Button
                                    type="submit"
                                    disabled={
                                        saving || changedRows.length === 0
                                    }
                                >
                                    შენახვა
                                </Button>
                            </div>
                        </div>
                    )}
                </form>
            )}
        </PortalLayout>
    );
}
