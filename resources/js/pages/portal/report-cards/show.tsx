import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft, FileText } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { firstError, formatDateTime, formatPercent } from '@/lib/assessment';

type Line = {
    id: number;
    subject: string;
    assessmentsCount: number;
    averagePercent: string | null;
    finalMark: string | null;
    teacherComment: string | null;
    isComplete: boolean;
    canEdit: boolean;
};

type Props = {
    card: {
        id: number;
        studentName: string;
        className: string;
        periodName: string;
        status: string;
        statusLabel: string;
        revision: number;
        homeroomComment: string | null;
        directorComment: string | null;
    };
    lines: Line[];
    history: {
        revision: number;
        from: string;
        to: string;
        note: string | null;
        actorName: string | null;
        createdAt: string | null;
    }[];
    publications: { id: number; revision: number; publishedAt: string }[];
    can: {
        homeroomSubmit: boolean;
        approve: boolean;
        publish: boolean;
        returnForChanges: boolean;
        revise: boolean;
    };
    returnableLines: { id: number; subject: string }[];
};

const inputClass =
    'h-11 w-full rounded-md border border-slate-300 px-3 text-sm disabled:bg-slate-50';
const textareaClass =
    'min-h-24 w-full rounded-md border border-slate-300 p-3 text-sm';

function post(
    url: string,
    data: Record<string, unknown>,
    success: string,
    done?: () => void,
) {
    router.post(url, data as Record<string, string>, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(success);
            done?.();
        },
        onError: (errors) => toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
    });
}

function LineEditor({ cardId, line }: { cardId: number; line: Line }) {
    const [finalMark, setFinalMark] = useState(line.finalMark ?? '');
    const [comment, setComment] = useState(line.teacherComment ?? '');
    const markId = `mark-${line.id}`;
    const commentId = `line-comment-${line.id}`;

    const save = (complete: boolean) =>
        post(
            `/portal/report-cards/${cardId}/lines/${line.id}`,
            { final_mark: finalMark, teacher_comment: comment, complete },
            complete ? 'საგანი დასრულებულად მოინიშნა.' : 'შენახულია.',
        );

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">{line.subject}</p>
                <p className="text-sm text-slate-500">
                    {line.assessmentsCount === 0
                        ? 'გამოქვეყნებული შეფასება არ არის'
                        : `ინფორმაციული საშუალო ${formatPercent(line.averagePercent)} · ${line.assessmentsCount} შეფასებიდან`}
                </p>
            </div>
            {line.canEdit ? (
                <div className="grid gap-3 md:grid-cols-[10rem_1fr]">
                    <div>
                        <label
                            htmlFor={markId}
                            className="mb-1 block text-xs font-medium text-slate-600"
                        >
                            საბოლოო შეფასება
                        </label>
                        <input
                            id={markId}
                            value={finalMark}
                            maxLength={32}
                            onChange={(e) => setFinalMark(e.target.value)}
                            className={inputClass}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor={commentId}
                            className="mb-1 block text-xs font-medium text-slate-600"
                        >
                            მასწავლებლის კომენტარი
                        </label>
                        <textarea
                            id={commentId}
                            value={comment}
                            maxLength={2000}
                            onChange={(e) => setComment(e.target.value)}
                            className={textareaClass}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2 md:col-span-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => save(false)}
                        >
                            {line.isComplete
                                ? 'შენახვა (დაბრუნდება შესავსებად)'
                                : 'დრაფტად შენახვა'}
                        </Button>
                        <Button type="button" onClick={() => save(true)}>
                            შენახვა და დასრულება
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="text-sm">
                    <p>
                        <span className="text-slate-500">შეფასება: </span>
                        <strong>{line.finalMark ?? '—'}</strong>
                        {!line.isComplete && (
                            <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                შესავსებია
                            </span>
                        )}
                    </p>
                    {line.teacherComment && (
                        <p className="mt-1 whitespace-pre-wrap text-slate-700">
                            {line.teacherComment}
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}

function ReasonForm({
    title,
    url,
    lines,
    submitLabel,
    success,
    hint,
}: {
    title: string;
    url: string;
    lines: { id: number; subject: string }[];
    submitLabel: string;
    success: string;
    hint: string;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [subjectIds, setSubjectIds] = useState<number[]>([]);
    const reasonId = `${url}-reason`;

    if (!open) {
        return (
            <Button variant="outline" onClick={() => setOpen(true)}>
                {title}
            </Button>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(url, { reason, subject_ids: subjectIds }, success, () =>
            setOpen(false),
        );
    };

    return (
        <form
            onSubmit={submit}
            className="w-full space-y-3 rounded-xl border border-slate-200 bg-white p-4"
        >
            <p className="font-medium">{title}</p>
            <p className="text-sm text-slate-600">{hint}</p>
            <fieldset className="grid gap-1 sm:grid-cols-2">
                <legend className="mb-1 text-sm font-medium">
                    მასწავლებელს დასაბრუნებელი საგნები
                </legend>
                {lines.map((line) => (
                    <label
                        key={line.id}
                        className="flex min-h-11 items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            className="h-4 w-4"
                            checked={subjectIds.includes(line.id)}
                            onChange={(e) =>
                                setSubjectIds((ids) =>
                                    e.target.checked
                                        ? [...ids, line.id]
                                        : ids.filter((id) => id !== line.id),
                                )
                            }
                        />
                        {line.subject}
                    </label>
                ))}
            </fieldset>
            <div>
                <label
                    htmlFor={reasonId}
                    className="mb-1 block text-sm font-medium"
                >
                    მიზეზი (აუდიტში ჩაიწერება)
                </label>
                <textarea
                    id={reasonId}
                    required
                    minLength={5}
                    maxLength={1000}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    className={textareaClass}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit">{submitLabel}</Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setOpen(false)}
                >
                    გაუქმება
                </Button>
            </div>
        </form>
    );
}

/**
 * One report card. What is editable or actionable is decided entirely by
 * the server (`can`, `line.canEdit`); this page only renders it.
 */
export default function ReportCardShow({
    card,
    lines,
    history,
    publications,
    can,
    returnableLines,
}: Props) {
    const [homeroomComment, setHomeroomComment] = useState('');
    const [directorComment, setDirectorComment] = useState('');
    const [confirmPublish, setConfirmPublish] = useState(false);
    const base = `/portal/report-cards/${card.id}`;

    return (
        <PortalLayout>
            <Head title={`${card.studentName} — ტაბელი`} />

            <Link
                href="/portal/report-cards"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ტაბელები
            </Link>

            <div className="mb-6">
                <h1 className="text-2xl">{card.studentName}</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {card.className} · {card.periodName} · რევიზია{' '}
                    {card.revision}
                </p>
                <p className="mt-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-sm font-medium">
                    {card.statusLabel}
                </p>
            </div>

            <section className="mb-6" aria-label="საგნები">
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {lines.map((line) => (
                        <LineEditor
                            key={line.id}
                            cardId={card.id}
                            line={line}
                        />
                    ))}
                </ul>
                <p className="mt-2 text-xs text-slate-500">
                    საშუალო არაწონიანი და ინფორმაციულია — საბოლოო შეფასებას
                    მასწავლებელი წერს სკოლის დამტკიცებული წესით.
                </p>
            </section>

            {(card.homeroomComment || card.directorComment) && (
                <section className="mb-6 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
                    {card.homeroomComment && (
                        <p>
                            <span className="font-medium">დამრიგებელი: </span>
                            {card.homeroomComment}
                        </p>
                    )}
                    {card.directorComment && (
                        <p>
                            <span className="font-medium">დირექტორი: </span>
                            {card.directorComment}
                        </p>
                    )}
                </section>
            )}

            <section
                className="mb-8 flex flex-wrap gap-3"
                aria-label="მოქმედებები"
            >
                {can.homeroomSubmit && (
                    <form
                        className="w-full space-y-2 rounded-xl border border-slate-200 bg-white p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(
                                `${base}/homeroom-submit`,
                                { comment: homeroomComment },
                                'გადაეგზავნა დირექტორს.',
                            );
                        }}
                    >
                        <label
                            htmlFor="homeroom-comment"
                            className="block text-sm font-medium"
                        >
                            დამრიგებლის კომენტარი (სავალდებულო)
                        </label>
                        <textarea
                            id="homeroom-comment"
                            required
                            maxLength={2000}
                            value={homeroomComment}
                            onChange={(e) => setHomeroomComment(e.target.value)}
                            className={textareaClass}
                        />
                        <Button type="submit">დირექტორთან გაგზავნა</Button>
                    </form>
                )}

                {can.approve && (
                    <form
                        className="w-full space-y-2 rounded-xl border border-slate-200 bg-white p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(
                                `${base}/approve`,
                                { comment: directorComment },
                                'დამტკიცდა.',
                            );
                        }}
                    >
                        <label
                            htmlFor="director-comment"
                            className="block text-sm font-medium"
                        >
                            დირექტორის კომენტარი (არასავალდებულო)
                        </label>
                        <textarea
                            id="director-comment"
                            maxLength={2000}
                            value={directorComment}
                            onChange={(e) => setDirectorComment(e.target.value)}
                            className={textareaClass}
                        />
                        <Button type="submit">დამტკიცება</Button>
                    </form>
                )}

                {can.publish &&
                    (confirmPublish ? (
                        <span className="flex flex-wrap items-center gap-2">
                            <span className="text-sm">
                                ტაბელს დაინახავენ მოსწავლე და მშობლები.
                            </span>
                            <Button
                                onClick={() =>
                                    post(
                                        `${base}/publish`,
                                        {},
                                        'ტაბელი გამოქვეყნდა.',
                                        () => setConfirmPublish(false),
                                    )
                                }
                            >
                                დადასტურება
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setConfirmPublish(false)}
                            >
                                გაუქმება
                            </Button>
                        </span>
                    ) : (
                        <Button onClick={() => setConfirmPublish(true)}>
                            გამოქვეყნება
                        </Button>
                    ))}

                {can.returnForChanges && (
                    <ReasonForm
                        title="შესწორებისთვის დაბრუნება"
                        url={`${base}/return`}
                        lines={returnableLines}
                        submitLabel="დაბრუნება"
                        success="ტაბელი დაბრუნდა."
                        hint="მონიშნული საგნები მასწავლებლებს დაუბრუნდება; თუ არცერთს არ მონიშნავთ, ტაბელი დამრიგებლის ეტაპზე დაბრუნდება."
                    />
                )}

                {can.revise && (
                    <ReasonForm
                        title="ახალი რევიზიის გახსნა"
                        url={`${base}/revise`}
                        lines={returnableLines}
                        submitLabel="რევიზიის გახსნა"
                        success="ახალი რევიზია გაიხსნა."
                        hint="ოჯახი ძველ გამოქვეყნებულ ვერსიას ხედავს, სანამ ახალი არ დამტკიცდება და გამოქვეყნდება."
                    />
                )}
            </section>

            {publications.length > 0 && (
                <section className="mb-8" aria-labelledby="publications">
                    <h2 id="publications" className="mb-3 text-lg">
                        გამოქვეყნებული ვერსიები
                    </h2>
                    <ul className="flex flex-wrap gap-2">
                        {publications.map((p) => (
                            <li key={p.id}>
                                <Link
                                    href={`/portal/report-card-documents/${p.id}`}
                                    className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-3 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                                >
                                    <FileText size={16} /> რევიზია {p.revision}{' '}
                                    · {formatDateTime(p.publishedAt)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {history.length > 0 && (
                <section aria-labelledby="history">
                    <h2 id="history" className="mb-3 text-lg">
                        ისტორია
                    </h2>
                    <ol className="space-y-2 text-sm">
                        {history.map((h, index) => (
                            <li
                                key={index}
                                className="rounded-lg bg-white p-3 ring-1 ring-slate-200"
                            >
                                <span className="text-slate-500">
                                    {h.createdAt
                                        ? formatDateTime(h.createdAt)
                                        : ''}{' '}
                                    · რ.{h.revision} · {h.actorName}
                                </span>
                                <br />
                                {h.from} → <strong>{h.to}</strong>
                                {h.note ? ` — ${h.note}` : ''}
                            </li>
                        ))}
                    </ol>
                </section>
            )}
        </PortalLayout>
    );
}
