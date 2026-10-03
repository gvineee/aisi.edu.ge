import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type FormEvent } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PortalLayout from '@/layouts/portal/portal-layout';

type Handoff = { plan: string; materials: string | null; notes: string | null };

type CoverRow = {
    id: number;
    date: string;
    subject: string | null;
    className: string | null;
    startsAt: string;
    endsAt: string;
    otherTeacherName: string;
    handoff: Handoff | null;
};

type RequestRow = {
    id: number;
    startsOn: string;
    endsOn: string;
    reason: string | null;
    status: string;
    decisionNote: string | null;
};

type Props = {
    requests: RequestRow[];
    coveredForMe: CoverRow[];
    iCover: CoverRow[];
};

const STATUS: Record<string, string> = {
    pending: 'განსახილველი',
    approved: 'დამტკიცებული',
    declined: 'უარყოფილი',
    withdrawn: 'გაუქმებული',
};

/**
 * Teacher coverage page: request cover for an absence, leave a plan for
 * each of my lessons someone else covers, and read the plan for lessons I
 * cover. Approval and substitute choice stay with the coordinators.
 */
export default function CoverageIndex({
    requests,
    coveredForMe,
    iCover,
}: Props) {
    const submitRequest = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        router.post('/portal/my-coverage/requests', new FormData(form), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: () => toast.error('შემოწმეთ თარიღები.'),
        });
    };

    const saveHandoff = (event: FormEvent<HTMLFormElement>, row: CoverRow) => {
        event.preventDefault();
        router.post(
            `/portal/substitutions/${row.id}/handoff`,
            new FormData(event.currentTarget),
            {
                preserveScroll: true,
                onError: () => toast.error('გეგმა ვერ შეინახა.'),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="ჩემი ჩანაცვლებები" />
            <Link
                href="/portal/teacher"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ჩემი სივრცე
            </Link>
            <h1 className="mb-6 text-2xl">ჩემი ჩანაცვლებები</h1>

            <section aria-labelledby="request-cover" className="mb-8">
                <h2 id="request-cover" className="mb-3 text-lg">
                    ჩანაცვლების მოთხოვნა
                </h2>
                <form
                    onSubmit={submitRequest}
                    className="grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-4 sm:items-end"
                >
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">დან</span>
                        <Input name="starts_on" type="date" required />
                    </label>
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">მდე</span>
                        <Input name="ends_on" type="date" required />
                    </label>
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">
                            მიზეზი (არასავალდებულო)
                        </span>
                        <Input name="reason" maxLength={500} />
                    </label>
                    <Button type="submit">გაგზავნა</Button>
                </form>
                {requests.length > 0 && (
                    <ul className="mt-3 divide-y divide-slate-100 rounded-xl bg-white ring-1 ring-slate-200">
                        {requests.map((r) => (
                            <li
                                key={r.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm"
                            >
                                <span>
                                    {r.startsOn} – {r.endsOn}
                                    {r.reason ? ` · ${r.reason}` : ''}
                                    {r.decisionNote
                                        ? ` · ${r.decisionNote}`
                                        : ''}
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                        {STATUS[r.status] ?? r.status}
                                    </span>
                                    {r.status === 'pending' && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `/portal/my-coverage/requests/${r.id}/withdraw`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            გაუქმება
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="covered-for-me" className="mb-8">
                <h2 id="covered-for-me" className="mb-3 text-lg">
                    ჩემს გაკვეთილებს ანაცვლებენ
                </h2>
                {coveredForMe.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                        მომავალი ჩანაცვლება არ გაქვთ.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {coveredForMe.map((row) => (
                            <li
                                key={row.id}
                                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            >
                                <p className="font-medium">
                                    {row.subject} · {row.className}
                                </p>
                                <p className="mb-3 text-sm text-slate-500">
                                    {row.date} · {row.startsAt}–{row.endsAt} ·
                                    შემცვლელი: {row.otherTeacherName}
                                </p>
                                <form
                                    onSubmit={(event) =>
                                        saveHandoff(event, row)
                                    }
                                    className="space-y-2"
                                >
                                    <label className="block text-sm">
                                        <span className="mb-1 block font-medium">
                                            გაკვეთილის გეგმა
                                        </span>
                                        <textarea
                                            name="plan"
                                            required
                                            maxLength={5000}
                                            defaultValue={
                                                row.handoff?.plan ?? ''
                                            }
                                            className="min-h-24 w-full rounded-md border border-slate-300 p-2"
                                        />
                                    </label>
                                    <label className="block text-sm">
                                        <span className="mb-1 block font-medium">
                                            მასალები / ბმულები
                                        </span>
                                        <textarea
                                            name="materials"
                                            maxLength={5000}
                                            defaultValue={
                                                row.handoff?.materials ?? ''
                                            }
                                            className="min-h-16 w-full rounded-md border border-slate-300 p-2"
                                        />
                                    </label>
                                    <label className="block text-sm">
                                        <span className="mb-1 block font-medium">
                                            შენიშვნა
                                        </span>
                                        <textarea
                                            name="notes"
                                            maxLength={2000}
                                            defaultValue={
                                                row.handoff?.notes ?? ''
                                            }
                                            className="min-h-12 w-full rounded-md border border-slate-300 p-2"
                                        />
                                    </label>
                                    <Button type="submit" size="sm">
                                        {row.handoff
                                            ? 'გეგმის განახლება'
                                            : 'გეგმის შენახვა'}
                                    </Button>
                                </form>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="i-cover">
                <h2 id="i-cover" className="mb-3 text-lg">
                    მე ვანაცვლებ
                </h2>
                {iCover.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                        დანიშნული ჩანაცვლება არ გაქვთ.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {iCover.map((row) => (
                            <li
                                key={row.id}
                                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            >
                                <p className="font-medium">
                                    {row.subject} · {row.className}
                                </p>
                                <p className="mb-2 text-sm text-slate-500">
                                    {row.date} · {row.startsAt}–{row.endsAt} ·{' '}
                                    {row.otherTeacherName}-ს ნაცვლად
                                </p>
                                {row.handoff ? (
                                    <div className="space-y-1 text-sm whitespace-pre-line">
                                        <p>{row.handoff.plan}</p>
                                        {row.handoff.materials && (
                                            <p>
                                                <strong>მასალები:</strong>{' '}
                                                {row.handoff.materials}
                                            </p>
                                        )}
                                        {row.handoff.notes && (
                                            <p>{row.handoff.notes}</p>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500">
                                        გეგმა ჯერ არ არის.
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
