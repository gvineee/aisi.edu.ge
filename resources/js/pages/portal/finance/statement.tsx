import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDay } from '@/lib/assessment';
import { INVOICE_STATUS } from '@/lib/finance';
import { formatMoney } from '@/lib/operations';

type Props = {
    student: {
        id: number;
        name: string;
        className: string | null;
        isActive: boolean;
        guardians: {
            name: string | null;
            email: string | null;
            financial: boolean;
        }[];
    };
    summary: {
        invoicedMinor: number;
        paidMinor: number;
        outstandingMinor: number;
        overdueMinor: number;
        creditMinor: number;
    };
    invoices: {
        id: number;
        number: string;
        status: string;
        issuedOn: string;
        dueOn: string;
        totalMinor: number;
        creditedMinor: number;
        paidMinor: number;
        outstandingMinor: number;
        currency: string;
    }[];
    payments: {
        id: number;
        receiptNumber: string;
        receivedOn: string;
        methodLabel: string;
        reference: string | null;
        amountMinor: number;
        creditMinor: number;
        currency: string;
        reversed: boolean;
    }[];
    contracts: {
        id: number;
        planName: string;
        status: string;
        discountPercent: number;
        discountReason: string | null;
        contractNumber: string | null;
        signedOn: string | null;
    }[];
};

/** One student's account: balance, contracts, invoices and payments. */
export default function FinanceStatement({
    student,
    summary,
    invoices,
    payments,
    contracts,
}: Props) {
    const stats: [string, number, string?][] = [
        ['გამოწერილი', summary.invoicedMinor],
        ['გადახდილი', summary.paidMinor],
        ['დავალიანება', summary.outstandingMinor],
        [
            'ვადაგადაცილებული',
            summary.overdueMinor,
            summary.overdueMinor > 0 ? 'text-red-700' : '',
        ],
        [
            'კრედიტი',
            summary.creditMinor,
            summary.creditMinor > 0 ? 'text-emerald-800' : '',
        ],
    ];

    return (
        <PortalLayout>
            <Head title={`ანგარიში — ${student.name}`} />
            <Link
                href="/portal/finance/debtors"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> დავალიანებები
            </Link>
            <h1 className="mb-1 text-2xl">{student.name}</h1>
            <p className="mb-4 text-sm text-slate-500">
                {student.className ?? 'კლასის გარეშე'}
                {!student.isActive && ' · არააქტიური'}
                {student.guardians.length > 0 &&
                    ` · ${student.guardians.map((g) => `${g.name}${g.financial ? '' : ' (ფინანსებზე წვდომის გარეშე)'}`).join(', ')}`}
            </p>

            <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                {stats.map(([label, value, tone]) => (
                    <div
                        key={label}
                        className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                    >
                        <dt className="text-xs text-slate-500">{label}</dt>
                        <dd
                            className={`mt-1 text-lg font-semibold ${tone ?? ''}`}
                        >
                            {formatMoney(value)}
                        </dd>
                    </div>
                ))}
            </dl>

            <Link
                href={`/portal/finance/payments?student=${student.id}`}
                className="mb-6 inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm text-white hover:bg-slate-800"
            >
                გადახდის გატარება
            </Link>

            <section
                className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                aria-labelledby="st-contracts"
            >
                <h2 id="st-contracts" className="mb-2 text-lg">
                    ხელშეკრულებები
                </h2>
                {contracts.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        საფასურის ხელშეკრულება არ აქვს —{' '}
                        <Link
                            href="/portal/finance/fee-plans"
                            className="underline"
                        >
                            დამატება
                        </Link>
                        .
                    </p>
                ) : (
                    <ul className="space-y-1 text-sm">
                        {contracts.map((c) => (
                            <li
                                key={c.id}
                                className={
                                    c.status === 'ended' ? 'opacity-60' : ''
                                }
                            >
                                {c.planName}
                                {c.contractNumber && ` · № ${c.contractNumber}`}
                                {c.signedOn && ` · ${formatDay(c.signedOn)}`}
                                {c.discountPercent > 0 &&
                                    ` · ფასდაკლება ${c.discountPercent}% (${c.discountReason})`}
                                {c.status === 'ended' && ' · დასრულებული'}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section
                className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                aria-labelledby="st-invoices"
            >
                <h2 id="st-invoices" className="mb-2 text-lg">
                    ინვოისები
                </h2>
                {invoices.length === 0 ? (
                    <p className="text-sm text-slate-500">ინვოისი არ არის.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {invoices.map((inv) => (
                            <li
                                key={inv.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-2"
                            >
                                <Link
                                    href={`/portal/finance/invoices/${inv.id}`}
                                    className="underline-offset-4 hover:underline"
                                >
                                    <strong>№ {inv.number}</strong> ·{' '}
                                    {formatDay(inv.issuedOn)} · ვადა{' '}
                                    {formatDay(inv.dueOn)}
                                    <span className="block text-slate-500">
                                        {INVOICE_STATUS[inv.status] ??
                                            inv.status}
                                    </span>
                                </Link>
                                <span>
                                    {formatMoney(inv.paidMinor, inv.currency)} /{' '}
                                    {formatMoney(
                                        inv.totalMinor - inv.creditedMinor,
                                        inv.currency,
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section
                className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                aria-labelledby="st-payments"
            >
                <h2 id="st-payments" className="mb-2 text-lg">
                    გადახდები
                </h2>
                {payments.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        გადახდა არ აღრიცხულა.
                    </p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {payments.map((p) => (
                            <li
                                key={p.id}
                                className={`flex flex-wrap items-center justify-between gap-2 py-2 ${p.reversed ? 'opacity-60' : ''}`}
                            >
                                <span>
                                    {p.receiptNumber} ·{' '}
                                    {formatDay(p.receivedOn)} · {p.methodLabel}
                                    {p.reference && ` · № ${p.reference}`}
                                    {p.reversed && ' · გაუქმებული'}
                                    {p.creditMinor > 0 &&
                                        ` · კრედიტი ${formatMoney(p.creditMinor)}`}
                                </span>
                                <span className="flex items-center gap-3">
                                    <strong
                                        className={
                                            p.reversed ? 'line-through' : ''
                                        }
                                    >
                                        {formatMoney(p.amountMinor, p.currency)}
                                    </strong>
                                    <a
                                        href={`/portal/finance/payments/${p.id}/receipt`}
                                        className="inline-flex min-h-11 items-center gap-1 text-slate-700"
                                        aria-label={`ქვითარი ${p.receiptNumber}`}
                                    >
                                        <Download size={14} aria-hidden /> PDF
                                    </a>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
