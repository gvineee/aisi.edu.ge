import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { MailCheck } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';

type Note = {
    id: number;
    studentName: string;
    className: string | null;
    submittedBy: string | null;
    startsOn: string;
    endsOn: string;
    reason: string;
    status: 'pending' | 'approved' | 'rejected';
    reviewNote: string | null;
    submittedAt: string;
};

type Props = {
    children: { id: number; name: string }[];
    myNotes: Note[];
    isReviewer: boolean;
    toReview: Note[];
    earliestDate: string;
    today: string;
};

const STATUS: Record<Note['status'], { label: string; className: string }> = {
    pending: {
        label: 'განსახილველი',
        className: 'bg-amber-100 text-amber-900',
    },
    approved: {
        label: 'დადასტურებული',
        className: 'bg-emerald-100 text-emerald-800',
    },
    rejected: { label: 'უარყოფილი', className: 'bg-slate-200 text-slate-700' },
};

const range = (note: Note) =>
    note.startsOn === note.endsOn
        ? formatDay(note.startsOn)
        : `${formatDay(note.startsOn)} – ${formatDay(note.endsOn)}`;

function StatusBadge({ status }: { status: Note['status'] }) {
    return (
        <span
            className={`inline-flex rounded-full px-3 py-1 text-xs font-medium ${STATUS[status].className}`}
        >
            {STATUS[status].label}
        </span>
    );
}

/**
 * Absence notes. A guardian tells the school why their child is or was
 * away; the homeroom teacher or the administration approves it, which
 * turns the covered absences into excused ones.
 */
export default function AbsenceNotesIndex({
    children,
    myNotes,
    isReviewer,
    toReview,
    earliestDate,
    today,
}: Props) {
    const [sending, setSending] = useState(false);

    const onError = (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

    const submitNote = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        setSending(true);
        router.post('/portal/absence-notes', new FormData(target), {
            preserveScroll: true,
            onSuccess: () => target.reset(),
            onError,
            onFinish: () => setSending(false),
        });
    };

    const review = (
        note: Note,
        decision: 'approve' | 'reject',
        reviewNote: string,
    ) => {
        setSending(true);
        router.post(
            `/portal/absence-notes/${note.id}/review`,
            { decision, review_note: reviewNote },
            {
                preserveScroll: true,
                onError,
                onFinish: () => setSending(false),
            },
        );
    };

    const nothing = children.length === 0 && !isReviewer;

    return (
        <PortalLayout>
            <Head title="გაცდენის შეტყობინებები" />
            <h1 className="mb-2 text-2xl">გაცდენის შეტყობინებები</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                მშობელი სკოლას აცნობებს ბავშვის გაცდენის მიზეზს. დამრიგებელი ან
                ადმინისტრაცია შეტყობინებას განიხილავს. დადასტურების შემდეგ ამ
                დღეების გაცდენები საპატიოდ ჩაითვლება.
            </p>

            {nothing && (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <MailCheck className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        თქვენთვის აქ განსახილველი ან გასაგზავნი არაფერია.
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        შეტყობინებებს განიხილავს კლასის დამრიგებელი და სკოლის
                        ადმინისტრაცია.
                    </p>
                </div>
            )}

            {children.length > 0 && (
                <section className="mb-8" aria-labelledby="new-note">
                    <h2 id="new-note" className="mb-3 text-lg">
                        ახალი შეტყობინება
                    </h2>
                    <form
                        onSubmit={submitNote}
                        className="space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                    >
                        <div>
                            <label
                                htmlFor="student_id"
                                className="mb-1 block text-sm font-medium"
                            >
                                ბავშვი
                            </label>
                            <select
                                id="student_id"
                                name="student_id"
                                required
                                defaultValue={
                                    children.length === 1 ? children[0].id : ''
                                }
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {children.length > 1 && (
                                    <option value="">აირჩიეთ</option>
                                )}
                                {children.map((child) => (
                                    <option key={child.id} value={child.id}>
                                        {child.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    htmlFor="starts_on"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    პირველი დღე
                                </label>
                                <Input
                                    id="starts_on"
                                    name="starts_on"
                                    type="date"
                                    min={earliestDate}
                                    defaultValue={today}
                                    required
                                />
                            </div>
                            <div>
                                <label
                                    htmlFor="ends_on"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    ბოლო დღე
                                </label>
                                <Input
                                    id="ends_on"
                                    name="ends_on"
                                    type="date"
                                    min={earliestDate}
                                    defaultValue={today}
                                    required
                                />
                            </div>
                        </div>
                        <div>
                            <label
                                htmlFor="reason"
                                className="mb-1 block text-sm font-medium"
                            >
                                მიზეზი
                            </label>
                            <textarea
                                id="reason"
                                name="reason"
                                rows={3}
                                maxLength={1000}
                                required
                                className="w-full rounded-md border border-slate-300 p-3 text-sm"
                            />
                            <p className="mt-1 text-xs text-slate-500">
                                შეტყობინებას ხედავს მხოლოდ კლასის დამრიგებელი და
                                სკოლის ადმინისტრაცია. ჯანმრთელობის დეტალები არ
                                არის აუცილებელი.
                            </p>
                        </div>
                        <Button type="submit" disabled={sending}>
                            გაგზავნა
                        </Button>
                    </form>

                    {myNotes.length > 0 && (
                        <ul className="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                            {myNotes.map((note) => (
                                <li
                                    key={note.id}
                                    className="flex flex-col gap-1 p-4 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {note.studentName} · {range(note)}
                                        </p>
                                        <p className="text-sm whitespace-pre-line text-slate-600">
                                            {note.reason}
                                        </p>
                                        {note.reviewNote && (
                                            <p className="mt-1 text-sm text-slate-500">
                                                სკოლის პასუხი: {note.reviewNote}
                                            </p>
                                        )}
                                    </div>
                                    <StatusBadge status={note.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            {isReviewer && (
                <section aria-labelledby="to-review">
                    <h2 id="to-review" className="mb-3 text-lg">
                        განსახილველი შეტყობინებები
                    </h2>
                    {toReview.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            შეტყობინება არ არის.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {toReview.map((note) => (
                                <ReviewCard
                                    key={note.id}
                                    note={note}
                                    sending={sending}
                                    onReview={review}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </PortalLayout>
    );
}

function ReviewCard({
    note,
    sending,
    onReview,
}: {
    note: Note;
    sending: boolean;
    onReview: (
        note: Note,
        decision: 'approve' | 'reject',
        reviewNote: string,
    ) => void;
}) {
    const [reviewNote, setReviewNote] = useState('');

    return (
        <li className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {note.studentName}
                        {note.className ? ` · ${note.className}` : ''}
                    </p>
                    <p className="text-sm text-slate-500">
                        {range(note)} · გამომგზავნი: {note.submittedBy}
                    </p>
                </div>
                <StatusBadge status={note.status} />
            </div>
            <p className="mb-3 text-sm whitespace-pre-line">{note.reason}</p>
            {note.status === 'pending' ? (
                <div className="space-y-2">
                    <label
                        htmlFor={`review-note-${note.id}`}
                        className="block text-sm font-medium"
                    >
                        პასუხი მშობელს (არასავალდებულო)
                    </label>
                    <Input
                        id={`review-note-${note.id}`}
                        value={reviewNote}
                        maxLength={500}
                        onChange={(event) => setReviewNote(event.target.value)}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={sending}
                            onClick={() =>
                                onReview(note, 'approve', reviewNote)
                            }
                        >
                            დადასტურება
                        </Button>
                        <Button
                            variant="outline"
                            disabled={sending}
                            onClick={() => onReview(note, 'reject', reviewNote)}
                        >
                            უარყოფა
                        </Button>
                    </div>
                </div>
            ) : (
                note.reviewNote && (
                    <p className="text-sm text-slate-500">
                        პასუხი: {note.reviewNote}
                    </p>
                )
            )}
        </li>
    );
}
