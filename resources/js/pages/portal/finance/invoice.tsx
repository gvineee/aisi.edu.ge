import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { ArrowLeft, Download, Mail } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import SandboxBanner from '@/components/portal/sandbox-banner';
import PaymentInstructions from '@/components/finance/payment-instructions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDateTime, formatDay } from '@/lib/assessment';
import {
    INVOICE_STATUS,
    newSubmissionKey,
    type PaymentInfo,
} from '@/lib/finance';
import { formatMoney, postWithToast } from '@/lib/operations';

type Props = {
    onlinePayments: boolean;
    sandboxEnabled: boolean;
    isStaff: boolean;
    paymentInfo: PaymentInfo;
    invoice: {
        id: number;
        number: string;
        status: string;
        studentId: number;
        studentName: string | null;
        issuedOn: string;
        dueOn: string;
        currency: string;
        totalMinor: number;
        creditedMinor: number;
        paidMinor: number;
        outstandingMinor: number;
        voidReason: string | null;
        creditNotes: {
            id: number;
            number: string;
            issuedOn: string;
            amountMinor: number;
            releasedMinor: number;
            reason: string;
        }[];
        items: {
            description: string;
            quantity: number;
            unitMinor: number;
            amountMinor: number;
        }[];
        allocations: {
            paymentId: number;
            receiptNumber: string | null;
            amountMinor: number;
            reversed: boolean;
            createdAt: string | null;
        }[];
        reminders: { sentAt: string; recipients: number }[];
    };
    payments: {
        id: number;
        receiptNumber: string;
        methodLabel: string;
        isManual: boolean;
        reversed: boolean;
        provider: string;
        amountMinor: number;
        creditMinor: number;
        refundedMinor: number;
        receivedAt: string;
        refunds: {
            id: number;
            amountMinor: number;
            status: string;
            method: string | null;
        }[];
    }[];
};

const STATUS = INVOICE_STATUS;

/**
 * One invoice: lines, credit notes, what has been applied to it, and (for
 * staff) the account's payments with credit, refunds, reminders, the
 * credit note form and the void action.
 */
export default function FinanceInvoice({
    onlinePayments,
    sandboxEnabled,
    isStaff,
    paymentInfo,
    invoice,
    payments,
}: Props) {
    const [voidReason, setVoidReason] = useState('');
    const [creditKey, setCreditKey] = useState(newSubmissionKey);
    const [creditProcessing, setCreditProcessing] = useState(false);
    const money = (minor: number) => formatMoney(minor, invoice.currency);
    const open = invoice.outstandingMinor > 0 && invoice.status !== 'void';
    const creditable =
        invoice.status !== 'void' &&
        invoice.totalMinor - invoice.creditedMinor > 0;

    const issueCreditNote = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = new FormData(form);
        data.set('idempotency_key', creditKey);

        router.post(
            `/portal/finance/invoices/${invoice.id}/credit-notes`,
            data as never,
            {
                preserveScroll: true,
                onStart: () => setCreditProcessing(true),
                onFinish: () => setCreditProcessing(false),
                onSuccess: () => {
                    toast.success(
                        'áƒ¡áƒáƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ áƒœáƒáƒ¢áƒ áƒ’áƒáƒ›áƒáƒ˜áƒ¬áƒ”áƒ áƒ.',
                    );
                    form.reset();
                    setCreditKey(newSubmissionKey());
                },
                onError: (errors) =>
                    toast.error(
                        firstError(errors) ??
                            'áƒ•áƒ”áƒ  áƒ’áƒáƒ›áƒáƒ˜áƒ¬áƒ”áƒ áƒ.',
                    ),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title={`áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜ â„– ${invoice.number}`} />
            <Link
                href="/portal/finance"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> áƒ¤áƒ˜áƒœáƒáƒœáƒ¡áƒ”áƒ‘áƒ˜
            </Link>
            <h1 className="mb-1 text-2xl">
                áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜ â„– {invoice.number}
            </h1>
            <p className="mb-4 text-sm text-slate-500">
                {invoice.studentName} Â· áƒ’áƒáƒ›áƒáƒ¬áƒ”áƒ áƒ˜áƒšáƒ˜áƒ{' '}
                {formatDay(invoice.issuedOn)} Â· áƒ•áƒáƒ“áƒ{' '}
                {formatDay(invoice.dueOn)} Â·{' '}
                {STATUS[invoice.status] ?? invoice.status}
            </p>
            {onlinePayments && <SandboxBanner />}

            <div className="mb-4 flex flex-wrap gap-2">
                <a
                    href={`/portal/finance/invoices/${invoice.id}/pdf`}
                    className="inline-flex min-h-11 items-center gap-1 rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                >
                    <Download size={14} aria-hidden /> PDF
                </a>
                {isStaff && open && (
                    <>
                        <Link
                            href={`/portal/finance/payments?student=${invoice.studentId}&invoice=${invoice.id}`}
                            className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm text-white hover:bg-slate-800"
                        >
                            áƒ’áƒáƒ“áƒáƒ®áƒ“áƒ˜áƒ¡ áƒ’áƒáƒ¢áƒáƒ áƒ”áƒ‘áƒ
                        </Link>
                        <Button
                            variant="outline"
                            onClick={() =>
                                postWithToast(
                                    `/portal/finance/invoices/${invoice.id}/remind`,
                                    {},
                                    'áƒ¨áƒ”áƒ®áƒ¡áƒ”áƒœáƒ”áƒ‘áƒ áƒ’áƒáƒ˜áƒ’áƒ–áƒáƒ•áƒœáƒ.',
                                )
                            }
                        >
                            <Mail size={16} /> áƒ¨áƒ”áƒ®áƒ¡áƒ”áƒœáƒ”áƒ‘áƒ
                            áƒ›áƒ¨áƒáƒ‘áƒ”áƒšáƒ¡
                        </Button>
                    </>
                )}
                {isStaff && (
                    <Link
                        href={`/portal/finance/accounts/${invoice.studentId}`}
                        className="inline-flex min-h-11 items-center rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                    >
                        áƒ›áƒáƒ¡áƒ¬áƒáƒ•áƒšáƒ˜áƒ¡ áƒáƒœáƒ’áƒáƒ áƒ˜áƒ¨áƒ˜
                    </Link>
                )}
            </div>

            {isStaff && invoice.reminders.length > 0 && (
                <p className="mb-4 text-sm text-slate-500">
                    áƒ¨áƒ”áƒ®áƒ¡áƒ”áƒœáƒ”áƒ‘áƒ”áƒ‘áƒ˜:{' '}
                    {invoice.reminders
                        .map(
                            (r) =>
                                `${formatDateTime(r.sentAt)} (${r.recipients})`,
                        )
                        .join(', ')}
                </p>
            )}

            {invoice.voidReason && (
                <p className="mb-4 rounded-lg bg-slate-100 p-3 text-sm">
                    áƒ’áƒáƒ£áƒ¥áƒ›áƒ”áƒ‘áƒ˜áƒ¡ áƒ›áƒ˜áƒ–áƒ”áƒ–áƒ˜:{' '}
                    {invoice.voidReason}
                </p>
            )}

            <div className="mb-6 overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
                <table className="w-full min-w-[480px] text-sm">
                    <caption className="sr-only">
                        áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜áƒ¡ áƒžáƒáƒ–áƒ˜áƒªáƒ˜áƒ”áƒ‘áƒ˜
                    </caption>
                    <thead>
                        <tr className="border-b border-slate-200 text-left">
                            <th scope="col" className="p-3">
                                áƒáƒ¦áƒ¬áƒ”áƒ áƒ
                            </th>
                            <th scope="col" className="p-3 text-right">
                                áƒ áƒáƒáƒ“.
                            </th>
                            <th scope="col" className="p-3 text-right">
                                áƒ¤áƒáƒ¡áƒ˜
                            </th>
                            <th scope="col" className="p-3 text-right">
                                áƒ¯áƒáƒ›áƒ˜
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.items.map((item, i) => (
                            <tr key={i} className="border-b border-slate-100">
                                <td className="p-3">{item.description}</td>
                                <td className="p-3 text-right">
                                    {item.quantity}
                                </td>
                                <td className="p-3 text-right">
                                    {money(item.unitMinor)}
                                </td>
                                <td className="p-3 text-right">
                                    {money(item.amountMinor)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th
                                scope="row"
                                colSpan={3}
                                className="p-3 text-right"
                            >
                                áƒ¡áƒ£áƒš
                            </th>
                            <td className="p-3 text-right font-semibold">
                                {money(invoice.totalMinor)}
                            </td>
                        </tr>
                        {invoice.creditedMinor > 0 && (
                            <tr>
                                <th
                                    scope="row"
                                    colSpan={3}
                                    className="p-3 text-right"
                                >
                                    áƒ¡áƒáƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ áƒœáƒáƒ¢áƒ˜áƒ—
                                    áƒ¨áƒ”áƒ›áƒªáƒ˜áƒ áƒ”áƒ‘áƒ
                                </th>
                                <td className="p-3 text-right">
                                    âˆ’{money(invoice.creditedMinor)}
                                </td>
                            </tr>
                        )}
                        <tr>
                            <th
                                scope="row"
                                colSpan={3}
                                className="p-3 text-right"
                            >
                                áƒ’áƒáƒ“áƒáƒ®áƒ“áƒ˜áƒšáƒ˜
                            </th>
                            <td className="p-3 text-right">
                                {money(invoice.paidMinor)}
                            </td>
                        </tr>
                        <tr>
                            <th
                                scope="row"
                                colSpan={3}
                                className="p-3 text-right"
                            >
                                áƒ“áƒáƒ áƒ©áƒ”áƒœáƒ˜áƒšáƒ˜
                            </th>
                            <td className="p-3 text-right font-semibold">
                                {money(invoice.outstandingMinor)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {open && onlinePayments && (
                <Button
                    className="mb-6"
                    onClick={() =>
                        router.post(
                            `/portal/finance/invoices/${invoice.id}/pay`,
                        )
                    }
                >
                    áƒ’áƒáƒ“áƒáƒ®áƒ“áƒ {money(invoice.outstandingMinor)}
                </Button>
            )}

            {open && !isStaff && !onlinePayments && (
                <PaymentInstructions
                    info={paymentInfo}
                    purpose={`áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜ â„– ${invoice.number}, ${invoice.studentName ?? ''}`}
                />
            )}

            {invoice.creditNotes.length > 0 && (
                <section className="mb-6" aria-labelledby="credit-notes">
                    <h2 id="credit-notes" className="mb-2 text-lg">
                        áƒ¡áƒáƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ áƒœáƒáƒ¢áƒ”áƒ‘áƒ˜
                    </h2>
                    <ul className="space-y-2 text-sm">
                        {invoice.creditNotes.map((c) => (
                            <li
                                key={c.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-3 ring-1 ring-slate-200"
                            >
                                <span>
                                    <strong>â„– {c.number}</strong> Â·{' '}
                                    {formatDay(c.issuedOn)} Â· âˆ’
                                    {money(c.amountMinor)}
                                    <span className="block text-slate-600">
                                        {c.reason}
                                        {c.releasedMinor > 0 &&
                                            ` Â· ${money(c.releasedMinor)} áƒ’áƒáƒ“áƒáƒ•áƒ˜áƒ“áƒ áƒáƒœáƒ’áƒáƒ áƒ˜áƒ¨áƒ˜áƒ¡ áƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ–áƒ”`}
                                    </span>
                                </span>
                                <a
                                    href={`/portal/finance/credit-notes/${c.id}/pdf`}
                                    className="inline-flex min-h-11 items-center gap-1 rounded-lg bg-white px-3 ring-1 ring-slate-200 hover:bg-slate-50"
                                >
                                    <Download size={14} aria-hidden /> PDF
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {invoice.allocations.length > 0 && (
                <section className="mb-6">
                    <h2 className="mb-2 text-lg">
                        áƒ©áƒáƒ áƒ˜áƒªáƒ®áƒ•áƒ”áƒ‘áƒ˜
                    </h2>
                    <ul className="space-y-1 text-sm">
                        {invoice.allocations.map((a, i) => (
                            <li
                                key={i}
                                className={
                                    a.reversed ? 'line-through opacity-60' : ''
                                }
                            >
                                {a.receiptNumber ?? `#${a.paymentId}`} Â·{' '}
                                {money(a.amountMinor)} Â·{' '}
                                {a.createdAt ? formatDateTime(a.createdAt) : ''}
                                {a.reversed &&
                                    ' (áƒ’áƒáƒ£áƒ¥áƒ›áƒ”áƒ‘áƒ£áƒšáƒ˜)'}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {isStaff && (
                <>
                    <section
                        className="mb-6 space-y-3"
                        aria-labelledby="account-payments"
                    >
                        <h2 id="account-payments" className="text-lg">
                            áƒáƒœáƒ’áƒáƒ áƒ˜áƒ¨áƒ˜áƒ¡
                            áƒ’áƒáƒ“áƒáƒ®áƒ“áƒ”áƒ‘áƒ˜
                        </h2>
                        {payments.length === 0 && (
                            <p className="text-sm text-slate-500">
                                áƒ’áƒáƒ“áƒáƒ®áƒ“áƒ áƒ¯áƒ”áƒ  áƒáƒ 
                                áƒ›áƒ˜áƒ¦áƒ”áƒ‘áƒ£áƒšáƒ.
                            </p>
                        )}
                        {payments.map((p) => (
                            <div
                                key={p.id}
                                className="space-y-2 rounded-xl bg-white p-4 text-sm ring-1 ring-slate-200"
                            >
                                <p className={p.reversed ? 'opacity-60' : ''}>
                                    <strong>{p.receiptNumber}</strong> Â·{' '}
                                    {p.methodLabel} Â· {money(p.amountMinor)} Â·{' '}
                                    {formatDateTime(p.receivedAt)} Â·
                                    áƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ˜ {money(p.creditMinor)}{' '}
                                    Â· áƒ“áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ£áƒšáƒ˜{' '}
                                    {money(p.refundedMinor)}
                                    {p.reversed &&
                                        ' Â· áƒ’áƒáƒ£áƒ¥áƒ›áƒ”áƒ‘áƒ£áƒšáƒ˜'}
                                </p>
                                {p.refunds.map((r) => (
                                    <p
                                        key={r.id}
                                        className="flex flex-wrap items-center gap-2 text-slate-600"
                                    >
                                        áƒ“áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ{' '}
                                        {money(r.amountMinor)} Â·{' '}
                                        {r.status === 'succeeded'
                                            ? 'áƒ“áƒáƒ¡áƒ áƒ£áƒšáƒ”áƒ‘áƒ£áƒšáƒ˜'
                                            : r.status === 'failed'
                                              ? 'áƒ•áƒ”áƒ  áƒ¨áƒ”áƒ¡áƒ áƒ£áƒšáƒ“áƒ'
                                              : 'áƒ›áƒáƒ—áƒ®áƒáƒ•áƒœáƒ˜áƒšáƒ˜'}
                                        {r.status === 'requested' &&
                                            sandboxEnabled && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        postWithToast(
                                                            `/portal/finance/sandbox/refunds/${r.id}/confirm`,
                                                            {},
                                                            'Sandbox: áƒ“áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ áƒ“áƒáƒ“áƒáƒ¡áƒ¢áƒ£áƒ áƒ“áƒ.',
                                                        )
                                                    }
                                                >
                                                    Sandbox:
                                                    áƒ“áƒáƒ“áƒáƒ¡áƒ¢áƒ£áƒ áƒ”áƒ‘áƒ
                                                </Button>
                                            )}
                                    </p>
                                ))}
                                {p.creditMinor > 0 && !p.reversed && (
                                    <div className="flex flex-wrap gap-3">
                                        {invoice.outstandingMinor > 0 &&
                                            invoice.status !== 'void' && (
                                                <form
                                                    className="flex items-end gap-2"
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        postWithToast(
                                                            `/portal/finance/payments/${p.id}/allocate`,
                                                            {
                                                                invoice_id:
                                                                    invoice.id,
                                                                amount: new FormData(
                                                                    e.currentTarget,
                                                                ).get('amount'),
                                                            },
                                                            'áƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ˜ áƒ©áƒáƒ˜áƒ áƒ˜áƒªáƒ®áƒ.',
                                                        );
                                                    }}
                                                >
                                                    <Input
                                                        aria-label="áƒ©áƒáƒ¡áƒáƒ áƒ˜áƒªáƒ®áƒ˜ áƒ—áƒáƒœáƒ®áƒ"
                                                        name="amount"
                                                        inputMode="decimal"
                                                        required
                                                        className="w-28"
                                                        placeholder="0.00"
                                                    />
                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                    >
                                                        áƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ˜áƒ¡
                                                        áƒ©áƒáƒ áƒ˜áƒªáƒ®áƒ•áƒ
                                                    </Button>
                                                </form>
                                            )}
                                        <form
                                            className="flex flex-wrap items-end gap-2"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                // Manual payments are paid back by hand
                                                // (bank transfer); provider payments
                                                // go through the provider.
                                                postWithToast(
                                                    p.isManual
                                                        ? `/portal/finance/payments/${p.id}/manual-refund`
                                                        : `/portal/finance/payments/${p.id}/refund`,
                                                    new FormData(
                                                        e.currentTarget,
                                                    ),
                                                    p.isManual
                                                        ? 'áƒ“áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ áƒáƒ¦áƒ˜áƒ áƒ˜áƒªáƒ®áƒ.'
                                                        : 'áƒ“áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ áƒ›áƒáƒ—áƒ®áƒáƒ•áƒœáƒ˜áƒšáƒ˜áƒ.',
                                                );
                                            }}
                                        >
                                            {p.isManual && (
                                                <input
                                                    type="hidden"
                                                    name="method"
                                                    value="bank_transfer"
                                                />
                                            )}
                                            <Input
                                                aria-label="áƒ“áƒáƒ¡áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ”áƒšáƒ˜ áƒ—áƒáƒœáƒ®áƒ"
                                                name="amount"
                                                inputMode="decimal"
                                                required
                                                className="w-28"
                                                placeholder="0.00"
                                            />
                                            <Input
                                                aria-label="áƒ›áƒ˜áƒ–áƒ”áƒ–áƒ˜"
                                                name="reason"
                                                required
                                                minLength={5}
                                                placeholder="áƒ›áƒ˜áƒ–áƒ”áƒ–áƒ˜"
                                            />
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                type="submit"
                                            >
                                                áƒ“áƒáƒ‘áƒ áƒ£áƒœáƒ”áƒ‘áƒ
                                            </Button>
                                        </form>
                                    </div>
                                )}
                            </div>
                        ))}
                    </section>

                    {creditable && (
                        <form
                            className="mb-6 space-y-2 rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            onSubmit={issueCreditNote}
                            aria-labelledby="credit-note-form"
                        >
                            <h2 id="credit-note-form" className="text-lg">
                                áƒ¡áƒáƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ áƒœáƒáƒ¢áƒ
                                (áƒ™áƒáƒ áƒ”áƒ¥áƒ¢áƒ˜áƒ áƒ”áƒ‘áƒ)
                            </h2>
                            <p className="text-sm text-slate-600">
                                áƒáƒ›áƒªáƒ˜áƒ áƒ”áƒ‘áƒ¡
                                áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜áƒ¡ áƒ—áƒáƒœáƒ®áƒáƒ¡.
                                áƒ—áƒ£ áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ–áƒ” áƒáƒ®áƒáƒš
                                áƒ¯áƒáƒ›áƒ–áƒ” áƒ›áƒ”áƒ¢áƒ˜áƒ
                                áƒ’áƒáƒ“áƒáƒ®áƒ“áƒ˜áƒšáƒ˜,
                                áƒ¡áƒ®áƒ•áƒáƒáƒ‘áƒ
                                áƒáƒœáƒ’áƒáƒ áƒ˜áƒ¨áƒ˜áƒ¡
                                áƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ–áƒ” áƒ’áƒáƒ“áƒáƒ•áƒ â€”
                                áƒ¨áƒ”áƒ›áƒ“áƒ”áƒ’ áƒ¨áƒ”áƒ’áƒ˜áƒ«áƒšáƒ˜áƒáƒ—
                                áƒ“áƒáƒ£áƒ‘áƒ áƒ£áƒœáƒáƒ— áƒáƒœ áƒ¡áƒ®áƒ•áƒ
                                áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ–áƒ”
                                áƒ©áƒáƒ áƒ˜áƒªáƒ®áƒáƒ—.
                                áƒ¡áƒáƒ™áƒ áƒ”áƒ“áƒ˜áƒ¢áƒ áƒœáƒáƒ¢áƒ áƒáƒ 
                                áƒ˜áƒ¨áƒšáƒ”áƒ‘áƒ.
                            </p>
                            <div className="flex flex-wrap items-end gap-2">
                                <div>
                                    <label
                                        htmlFor="credit-amount"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        áƒ—áƒáƒœáƒ®áƒ (áƒ›áƒáƒ¥áƒ¡.{' '}
                                        {money(
                                            invoice.totalMinor -
                                                invoice.creditedMinor,
                                        )}
                                        )
                                    </label>
                                    <Input
                                        id="credit-amount"
                                        name="amount"
                                        inputMode="decimal"
                                        required
                                        className="w-32"
                                        placeholder="0.00"
                                    />
                                </div>
                                <div className="min-w-[220px] flex-1">
                                    <label
                                        htmlFor="credit-reason"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        áƒ¡áƒáƒ¤áƒ£áƒ«áƒ•áƒ”áƒšáƒ˜
                                    </label>
                                    <Input
                                        id="credit-reason"
                                        name="reason"
                                        required
                                        minLength={5}
                                        maxLength={500}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={creditProcessing}
                                >
                                    áƒ’áƒáƒ›áƒáƒ¬áƒ”áƒ áƒ
                                </Button>
                            </div>
                        </form>
                    )}

                    {invoice.status !== 'void' &&
                        invoice.paidMinor === 0 &&
                        invoice.creditedMinor === 0 && (
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    postWithToast(
                                        `/portal/finance/invoices/${invoice.id}/void`,
                                        { reason: voidReason },
                                        'áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜ áƒ’áƒáƒ£áƒ¥áƒ›áƒ“áƒ.',
                                    );
                                }}
                            >
                                <div className="min-w-[220px] flex-1">
                                    <label
                                        htmlFor="void-reason"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        áƒ’áƒáƒ£áƒ¥áƒ›áƒ”áƒ‘áƒ˜áƒ¡
                                        áƒ›áƒ˜áƒ–áƒ”áƒ–áƒ˜
                                    </label>
                                    <Input
                                        id="void-reason"
                                        value={voidReason}
                                        onChange={(e) =>
                                            setVoidReason(e.target.value)
                                        }
                                        required
                                        minLength={5}
                                    />
                                </div>
                                <Button type="submit" variant="destructive">
                                    áƒ˜áƒœáƒ•áƒáƒ˜áƒ¡áƒ˜áƒ¡
                                    áƒ’áƒáƒ£áƒ¥áƒ›áƒ”áƒ‘áƒ
                                </Button>
                            </form>
                        )}
                </>
            )}
        </PortalLayout>
    );
}
