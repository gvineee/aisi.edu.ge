import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import { Download } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import FinanceNav from '@/components/finance/finance-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDay } from '@/lib/assessment';
import {
    MONEY_PATTERN,
    PAYMENT_METHODS,
    newSubmissionKey,
    todayTbilisi,
} from '@/lib/finance';
import { formatMoney, postWithToast } from '@/lib/operations';

type PaymentRow = {
    id: number;
    receiptNumber: string;
    studentId: number | null;
    studentName: string | null;
    className: string | null;
    receivedOn: string;
    method: string | null;
    methodLabel: string;
    reference: string | null;
    note: string | null;
    amountMinor: number;
    creditMinor: number;
    refundedMinor: number;
    currency: string;
    isManual: boolean;
    reversed: boolean;
    reversalReason: string | null;
    refunds: { id: number; amountMinor: number; status: string; method: string | null }[];
};

type Props = {
    filters: { from: string; to: string; method: string | null };
    payments: PaymentRow[];
    totals: { receivedMinor: number; count: number };
    students: { id: number; name: string; className: string | null }[];
    openInvoices: Record<
        string,
        { id: number; number: string; dueOn: string; outstandingMinor: number }[]
    >;
    preselectStudentId: number | null;
    preselectInvoiceId: number | null;
};

const fieldClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

function PaymentActions({ payment }: { payment: PaymentRow }) {
    const [open, setOpen] = useState<'reverse' | 'refund' | null>(null);

    if (payment.reversed) {
        return null;
    }

    return (
        <div className="mt-2">
            <div className="flex flex-wrap gap-2">
                {payment.isManual && payment.refundedMinor === 0 && (
                    <Button
                        size="sm"
                        variant="outline"
                        aria-expanded={open === 'reverse'}
                        onClick={() =>
                            setOpen(open === 'reverse' ? null : 'reverse')
                        }
                    >
                        გატარების გაუქმება
                    </Button>
                )}
                {payment.creditMinor > 0 && (
                    <Button
                        size="sm"
                        variant="outline"
                        aria-expanded={open === 'refund'}
                        onClick={() =>
                            setOpen(open === 'refund' ? null : 'refund')
                        }
                    >
                        კრედიტის დაბრუნება
                    </Button>
                )}
            </div>
            {open === 'reverse' && (
                <form
                    className="mt-2 flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        postWithToast(
                            `/portal/finance/payments/${payment.id}/reverse`,
                            new FormData(e.currentTarget),
                            'გატარება გაუქმდა.',
                            () => setOpen(null),
                        );
                    }}
                >
                    <div className="min-w-[220px] flex-1">
                        <label
                            htmlFor={`rev-${payment.id}`}
                            className="mb-1 block text-xs font-medium"
                        >
                            მიზეზი (მაგ. არასწორი მოსწავლე ან თანხა)
                        </label>
                        <Input
                            id={`rev-${payment.id}`}
                            name="reason"
                            required
                            minLength={5}
                        />
                    </div>
                    <Button size="sm" variant="destructive" type="submit">
                        გაუქმება
                    </Button>
                </form>
            )}
            {open === 'refund' && (
                <form
                    className="mt-2 flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        postWithToast(
                            `/portal/finance/payments/${payment.id}/manual-refund`,
                            new FormData(e.currentTarget),
                            'დაბრუნება აღირიცხა.',
                            () => setOpen(null),
                        );
                    }}
                >
                    <div>
                        <label
                            htmlFor={`rf-a-${payment.id}`}
                            className="mb-1 block text-xs font-medium"
                        >
                            თანხა (მაქს. {formatMoney(payment.creditMinor)})
                        </label>
                        <Input
                            id={`rf-a-${payment.id}`}
                            name="amount"
                            inputMode="decimal"
                            pattern={MONEY_PATTERN}
                            required
                            className="w-32"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor={`rf-m-${payment.id}`}
                            className="mb-1 block text-xs font-medium"
                        >
                            როგორ დაუბრუნდა
                        </label>
                        <select
                            id={`rf-m-${payment.id}`}
                            name="method"
                            className={fieldClass}
                        >
                            <option value="bank_transfer">
                                საბანკო გადარიცხვა
                            </option>
                            <option value="cash">ნაღდი</option>
                        </select>
                    </div>
                    <div className="min-w-[180px] flex-1">
                        <label
                            htmlFor={`rf-r-${payment.id}`}
                            className="mb-1 block text-xs font-medium"
                        >
                            მიზეზი
                        </label>
                        <Input
                            id={`rf-r-${payment.id}`}
                            name="reason"
                            required
                            minLength={5}
                        />
                    </div>
                    <Button size="sm" type="submit">
                        დაბრუნების აღრიცხვა
                    </Button>
                </form>
            )}
        </div>
    );
}

/**
 * Payments journal + "record a payment" (cash book / bank statement).
 * The submission key makes a double click or retry book only once.
 */
export default function FinancePayments({
    filters,
    payments,
    totals,
    students,
    openInvoices,
    preselectStudentId,
    preselectInvoiceId,
}: Props) {
    const [studentId, setStudentId] = useState<string>(
        preselectStudentId ? String(preselectStudentId) : '',
    );
    const [method, setMethod] = useState('bank_transfer');
    const [submissionKey, setSubmissionKey] = useState(newSubmissionKey);
    const [processing, setProcessing] = useState(false);
    const [studentQuery, setStudentQuery] = useState('');

    const matchingStudents = useMemo(() => {
        const q = studentQuery.trim().toLowerCase();

        return q === ''
            ? students
            : students.filter((s) =>
                  `${s.name} ${s.className ?? ''}`.toLowerCase().includes(q),
              );
    }, [students, studentQuery]);

    const invoicesForStudent = studentId ? (openInvoices[studentId] ?? []) : [];

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = new FormData(form);
        data.set('idempotency_key', submissionKey);

        router.post('/portal/finance/payments', data as never, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                toast.success('გადახდა გატარდა.');
                form.reset();
                setStudentId('');
                setSubmissionKey(newSubmissionKey());
            },
            onError: (errors) =>
                toast.error(firstError(errors) ?? 'ვერ გატარდა.'),
        });
    };

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(
            '/portal/finance/payments',
            Object.fromEntries(
                [...data.entries()].filter(([, v]) => v !== ''),
            ) as Record<string, string>,
            { preserveScroll: true, replace: true },
        );
    };

    return (
        <PortalLayout>
            <Head title="გადახდები" />
            <h1 className="mb-4 text-2xl">გადახდები</h1>
            <FinanceNav current="/portal/finance/payments" />

            <form
                onSubmit={submit}
                className="mb-6 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                aria-labelledby="record-title"
            >
                <h2 id="record-title" className="text-lg">
                    გადახდის გატარება
                </h2>
                <p className="text-sm text-slate-500">
                    ნაღდი, საბანკო გადარიცხვა ან POS — სალაროს წიგნიდან ან
                    საბანკო ამონაწერიდან. თანხა ჯერ არჩეულ ინვოისს ფარავს, თუ
                    არ აირჩიეთ — ყველაზე ძველ ვადიანს; ნაშთი კრედიტად რჩება.
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label
                            htmlFor="pay-student-q"
                            className="mb-1 block text-sm font-medium"
                        >
                            მოსწავლის ძებნა
                        </label>
                        <Input
                            id="pay-student-q"
                            value={studentQuery}
                            onChange={(e) => setStudentQuery(e.target.value)}
                            placeholder="სახელი ან კლასი"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="pay-student"
                            className="mb-1 block text-sm font-medium"
                        >
                            მოსწავლე
                        </label>
                        <select
                            id="pay-student"
                            name="student_id"
                            required
                            value={studentId}
                            onChange={(e) => setStudentId(e.target.value)}
                            className={fieldClass}
                        >
                            <option value="">— აირჩიეთ —</option>
                            {matchingStudents.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                    {s.className ? ` · ${s.className}` : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label
                            htmlFor="pay-invoice"
                            className="mb-1 block text-sm font-medium"
                        >
                            ინვოისი
                        </label>
                        <select
                            id="pay-invoice"
                            name="invoice_id"
                            defaultValue={
                                preselectInvoiceId
                                    ? String(preselectInvoiceId)
                                    : ''
                            }
                            key={studentId}
                            className={fieldClass}
                        >
                            <option value="">
                                ავტომატურად (უძველესი ვადით)
                            </option>
                            {invoicesForStudent.map((inv) => (
                                <option key={inv.id} value={inv.id}>
                                    № {inv.number} · ვადა {formatDay(inv.dueOn)}{' '}
                                    · {formatMoney(inv.outstandingMinor)}
                                </option>
                            ))}
                        </select>
                        {studentId && invoicesForStudent.length === 0 && (
                            <p className="mt-1 text-xs text-slate-500">
                                ღია ინვოისი არ აქვს — თანხა კრედიტად დარჩება.
                            </p>
                        )}
                    </div>
                    <div>
                        <label
                            htmlFor="pay-amount"
                            className="mb-1 block text-sm font-medium"
                        >
                            თანხა (₾)
                        </label>
                        <Input
                            id="pay-amount"
                            name="amount"
                            inputMode="decimal"
                            pattern={MONEY_PATTERN}
                            required
                            placeholder="0.00"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="pay-method"
                            className="mb-1 block text-sm font-medium"
                        >
                            მეთოდი
                        </label>
                        <select
                            id="pay-method"
                            name="method"
                            value={method}
                            onChange={(e) => setMethod(e.target.value)}
                            className={fieldClass}
                        >
                            {PAYMENT_METHODS.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label
                            htmlFor="pay-date"
                            className="mb-1 block text-sm font-medium"
                        >
                            მიღების თარიღი
                        </label>
                        <Input
                            id="pay-date"
                            name="received_on"
                            type="date"
                            required
                            max={todayTbilisi()}
                            defaultValue={todayTbilisi()}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="pay-ref"
                            className="mb-1 block text-sm font-medium"
                        >
                            საბუთის № {method === 'bank_transfer' || method === 'pos' ? '(სავალდებულო)' : '(არასავალდებულო)'}
                        </label>
                        <Input
                            id="pay-ref"
                            name="reference"
                            maxLength={100}
                            required={
                                method === 'bank_transfer' || method === 'pos'
                            }
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="pay-note"
                            className="mb-1 block text-sm font-medium"
                        >
                            შენიშვნა
                        </label>
                        <Input id="pay-note" name="note" maxLength={500} />
                    </div>
                </div>
                <Button type="submit" disabled={processing}>
                    {processing ? 'ინახება…' : 'გატარება'}
                </Button>
            </form>

            <section
                className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                aria-labelledby="journal-title"
            >
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="journal-title" className="text-lg">
                        ჟურნალი · {totals.count} გადახდა ·{' '}
                        {formatMoney(totals.receivedMinor)}
                    </h2>
                    <a
                        href={`/portal/finance/exports/payments?from=${filters.from}&to=${filters.to}`}
                        className="inline-flex min-h-11 items-center gap-1 text-sm text-slate-700 underline-offset-4 hover:underline"
                    >
                        <Download size={14} aria-hidden /> Excel
                    </a>
                </div>
                <form
                    onSubmit={applyFilters}
                    className="mb-4 flex flex-wrap items-end gap-2"
                >
                    <div>
                        <label
                            htmlFor="f-from"
                            className="mb-1 block text-xs font-medium"
                        >
                            დან
                        </label>
                        <Input
                            id="f-from"
                            name="from"
                            type="date"
                            defaultValue={filters.from}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="f-to"
                            className="mb-1 block text-xs font-medium"
                        >
                            მდე
                        </label>
                        <Input
                            id="f-to"
                            name="to"
                            type="date"
                            defaultValue={filters.to}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="f-method"
                            className="mb-1 block text-xs font-medium"
                        >
                            მეთოდი
                        </label>
                        <select
                            id="f-method"
                            name="method"
                            defaultValue={filters.method ?? ''}
                            className={fieldClass}
                        >
                            <option value="">ყველა</option>
                            {PAYMENT_METHODS.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit" variant="outline">
                        ჩვენება
                    </Button>
                </form>

                {payments.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        ამ პერიოდში გადახდა არ აღრიცხულა.
                    </p>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {payments.map((p) => (
                            <li
                                key={p.id}
                                className={`py-3 text-sm ${p.reversed ? 'opacity-60' : ''}`}
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span>
                                        <strong>{p.receiptNumber}</strong> ·{' '}
                                        {formatDay(p.receivedOn)} ·{' '}
                                        {p.studentId ? (
                                            <Link
                                                href={`/portal/finance/accounts/${p.studentId}`}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {p.studentName}
                                            </Link>
                                        ) : (
                                            p.studentName
                                        )}
                                        {p.className ? ` (${p.className})` : ''}
                                    </span>
                                    <strong
                                        className={
                                            p.reversed ? 'line-through' : ''
                                        }
                                    >
                                        {formatMoney(p.amountMinor, p.currency)}
                                    </strong>
                                </div>
                                <p className="text-slate-500">
                                    {p.methodLabel}
                                    {p.reference ? ` · № ${p.reference}` : ''}
                                    {p.creditMinor > 0
                                        ? ` · კრედიტი ${formatMoney(p.creditMinor)}`
                                        : ''}
                                    {p.refundedMinor > 0
                                        ? ` · დაბრუნებული ${formatMoney(p.refundedMinor)}`
                                        : ''}
                                    {p.note ? ` · ${p.note}` : ''}
                                </p>
                                {p.reversed && (
                                    <p className="text-red-700">
                                        გაუქმებულია: {p.reversalReason}
                                    </p>
                                )}
                                <a
                                    href={`/portal/finance/payments/${p.id}/receipt`}
                                    className="inline-flex min-h-11 items-center gap-1 text-slate-700 underline-offset-4 hover:underline"
                                >
                                    <Download size={14} aria-hidden /> ქვითარი
                                    (PDF)
                                </a>
                                <PaymentActions payment={p} />
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
