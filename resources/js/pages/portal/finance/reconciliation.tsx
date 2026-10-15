import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, TriangleAlert } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import SandboxBanner from '@/components/portal/sandbox-banner';
import FinanceNav from '@/components/finance/finance-nav';
import { formatDateTime } from '@/lib/assessment';
import { formatMoney } from '@/lib/operations';

type Props = {
    totals: {
        paymentsMinor: number;
        allocatedMinor: number;
        creditMinor: number;
        refundedMinor: number;
        reversedMinor: number;
        outstandingMinor: number;
    };
    paymentIssues: { paymentId: number; providerPaymentId: string }[];
    invoiceIssues: { invoiceId: number; number: string }[];
    succeededWithoutPayment: string[];
    eventOutcomes: Record<string, number>;
    attentionEvents: {
        eventId: string;
        type: string;
        outcome: string;
        detail: string | null;
        receivedAt: string;
    }[];
    balanced: boolean;
    onlinePayments: boolean;
};

/** Accountant consistency report over payments, allocations and webhooks. */
export default function FinanceReconciliation({
    totals,
    paymentIssues,
    invoiceIssues,
    succeededWithoutPayment,
    eventOutcomes,
    attentionEvents,
    balanced,
    onlinePayments,
}: Props) {
    const rows: [string, number][] = [
        ['მიღებული გადახდები', totals.paymentsMinor],
        ['ინვოისებზე ჩარიცხული', totals.allocatedMinor],
        ['ანგარიშების კრედიტი', totals.creditMinor],
        ['დაბრუნებული', totals.refundedMinor],
        ['გაუქმებული გატარებები', totals.reversedMinor],
        ['ღია დავალიანება', totals.outstandingMinor],
    ];

    return (
        <PortalLayout>
            <Head title="შეჯერება" />
            <Link
                href="/portal/finance"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ფინანსები
            </Link>
            <h1 className="mb-4 text-2xl">შეჯერება</h1>
            <FinanceNav current="/portal/finance/reconciliation" />
            {onlinePayments && <SandboxBanner />}

            <p
                role="status"
                className={`mb-6 flex items-center gap-2 rounded-lg p-4 text-sm ${balanced ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900'}`}
            >
                {balanced ? (
                    <CheckCircle2 aria-hidden />
                ) : (
                    <TriangleAlert aria-hidden />
                )}
                {balanced
                    ? 'ყველა გადახდა და ინვოისი შეჯერებულია.'
                    : 'აღმოჩენილია შეუსაბამობა — იხილეთ ქვემოთ.'}
            </p>

            <dl className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                    >
                        <dt className="text-xs text-slate-500">{label}</dt>
                        <dd className="text-lg font-semibold">
                            {formatMoney(value)}
                        </dd>
                    </div>
                ))}
            </dl>

            {(paymentIssues.length > 0 ||
                invoiceIssues.length > 0 ||
                succeededWithoutPayment.length > 0) && (
                <section className="mb-6 space-y-1 rounded-xl bg-white p-4 text-sm ring-1 ring-slate-200">
                    {paymentIssues.map((p) => (
                        <p key={p.paymentId}>
                            გადახდა #{p.paymentId} ({p.providerPaymentId}) არ
                            ჯამდება.
                        </p>
                    ))}
                    {invoiceIssues.map((i) => (
                        <p key={i.invoiceId}>
                            ინვოისი № {i.number}: გადახდილი ≠ ჩარიცხვების ჯამს.
                        </p>
                    ))}
                    {succeededWithoutPayment.map((ref) => (
                        <p key={ref}>
                            დადასტურებული intent გადახდის ჩანაწერის გარეშე:{' '}
                            {ref}
                        </p>
                    ))}
                </section>
            )}

            <section
                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                aria-labelledby="webhooks"
            >
                <h2 id="webhooks" className="mb-2 text-lg">
                    ბანკის შეტყობინებები
                </h2>
                <p className="mb-3 text-sm text-slate-600">
                    {Object.entries(eventOutcomes)
                        .map(([outcome, count]) => `${outcome}: ${count}`)
                        .join(' · ') || 'შეტყობინება ჯერ არ მიღებულა.'}
                </p>
                {attentionEvents.length > 0 && (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {attentionEvents.map((e) => (
                            <li key={e.eventId} className="py-2">
                                <strong>{e.outcome}</strong> · {e.type} ·{' '}
                                {formatDateTime(e.receivedAt)}
                                {e.detail ? ` — ${e.detail}` : ''}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
