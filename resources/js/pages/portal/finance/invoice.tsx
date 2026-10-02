import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import SandboxBanner from '@/components/portal/sandbox-banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatDay } from '@/lib/assessment';
import { formatMoney, postWithToast } from '@/lib/operations';

type Props = {
    isStaff: boolean;
    invoice: {
        id: number;
        number: string;
        status: string;
        studentName: string | null;
        issuedOn: string;
        dueOn: string;
        currency: string;
        totalMinor: number;
        paidMinor: number;
        outstandingMinor: number;
        voidReason: string | null;
        items: {
            description: string;
            quantity: number;
            unitMinor: number;
            amountMinor: number;
        }[];
        allocations: {
            paymentId: number;
            amountMinor: number;
            createdAt: string | null;
        }[];
    };
    payments: {
        id: number;
        providerPaymentId: string;
        amountMinor: number;
        creditMinor: number;
        refundedMinor: number;
        receivedAt: string;
        refunds: { id: number; amountMinor: number; status: string }[];
    }[];
};

const STATUS: Record<string, string> = {
    issued: 'გადასახდელი',
    partially_paid: 'ნაწილობრივ გადახდილი',
    paid: 'გადახდილი',
    void: 'გაუქმებული',
};

/**
 * One invoice: lines, what has been applied to it, and (for staff) the
 * account's payments with credit, refunds and the void action.
 */
export default function FinanceInvoice({ isStaff, invoice, payments }: Props) {
    const [voidReason, setVoidReason] = useState('');
    const money = (minor: number) => formatMoney(minor, invoice.currency);

    return (
        <PortalLayout>
            <Head title={`ინვოისი № ${invoice.number}`} />
            <Link
                href="/portal/finance"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ფინანსები
            </Link>
            <h1 className="mb-1 text-2xl">ინვოისი № {invoice.number}</h1>
            <p className="mb-4 text-sm text-slate-500">
                {invoice.studentName} · გამოწერილია{' '}
                {formatDay(invoice.issuedOn)} · ვადა {formatDay(invoice.dueOn)}{' '}
                · {STATUS[invoice.status] ?? invoice.status}
            </p>
            <SandboxBanner />

            {invoice.voidReason && (
                <p className="mb-4 rounded-lg bg-slate-100 p-3 text-sm">
                    გაუქმების მიზეზი: {invoice.voidReason}
                </p>
            )}

            <div className="mb-6 overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
                <table className="w-full min-w-[480px] text-sm">
                    <caption className="sr-only">ინვოისის პოზიციები</caption>
                    <thead>
                        <tr className="border-b border-slate-200 text-left">
                            <th scope="col" className="p-3">
                                აღწერა
                            </th>
                            <th scope="col" className="p-3 text-right">
                                რაოდ.
                            </th>
                            <th scope="col" className="p-3 text-right">
                                ფასი
                            </th>
                            <th scope="col" className="p-3 text-right">
                                ჯამი
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
                                სულ
                            </th>
                            <td className="p-3 text-right font-semibold">
                                {money(invoice.totalMinor)}
                            </td>
                        </tr>
                        <tr>
                            <th
                                scope="row"
                                colSpan={3}
                                className="p-3 text-right"
                            >
                                გადახდილი
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
                                დარჩენილი
                            </th>
                            <td className="p-3 text-right font-semibold">
                                {money(invoice.outstandingMinor)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {invoice.outstandingMinor > 0 && invoice.status !== 'void' && (
                <Button
                    className="mb-6"
                    onClick={() =>
                        router.post(
                            `/portal/finance/invoices/${invoice.id}/pay`,
                        )
                    }
                >
                    გადახდა {money(invoice.outstandingMinor)}
                </Button>
            )}

            {invoice.allocations.length > 0 && (
                <section className="mb-6">
                    <h2 className="mb-2 text-lg">ჩარიცხვები</h2>
                    <ul className="space-y-1 text-sm">
                        {invoice.allocations.map((a, i) => (
                            <li key={i}>
                                გადახდა #{a.paymentId} · {money(a.amountMinor)}{' '}
                                ·{' '}
                                {a.createdAt ? formatDateTime(a.createdAt) : ''}
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
                            ანგარიშის გადახდები
                        </h2>
                        {payments.length === 0 && (
                            <p className="text-sm text-slate-500">
                                გადახდა ჯერ არ მიღებულა.
                            </p>
                        )}
                        {payments.map((p) => (
                            <div
                                key={p.id}
                                className="space-y-2 rounded-xl bg-white p-4 text-sm ring-1 ring-slate-200"
                            >
                                <p>
                                    <strong>#{p.id}</strong> ·{' '}
                                    {money(p.amountMinor)} ·{' '}
                                    {formatDateTime(p.receivedAt)} · კრედიტი{' '}
                                    {money(p.creditMinor)} · დაბრუნებული{' '}
                                    {money(p.refundedMinor)}
                                </p>
                                {p.refunds.map((r) => (
                                    <p
                                        key={r.id}
                                        className="flex flex-wrap items-center gap-2 text-slate-600"
                                    >
                                        დაბრუნება {money(r.amountMinor)} ·{' '}
                                        {r.status}
                                        {r.status === 'requested' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    postWithToast(
                                                        `/portal/finance/sandbox/refunds/${r.id}/confirm`,
                                                        {},
                                                        'Sandbox: დაბრუნება დადასტურდა.',
                                                    )
                                                }
                                            >
                                                Sandbox: დადასტურება
                                            </Button>
                                        )}
                                    </p>
                                ))}
                                {p.creditMinor > 0 && (
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
                                                            'კრედიტი ჩაირიცხა.',
                                                        );
                                                    }}
                                                >
                                                    <Input
                                                        aria-label="ჩასარიცხი თანხა"
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
                                                        კრედიტის ჩარიცხვა
                                                    </Button>
                                                </form>
                                            )}
                                        <form
                                            className="flex flex-wrap items-end gap-2"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                postWithToast(
                                                    `/portal/finance/payments/${p.id}/refund`,
                                                    new FormData(
                                                        e.currentTarget,
                                                    ),
                                                    'დაბრუნება მოთხოვნილია.',
                                                );
                                            }}
                                        >
                                            <Input
                                                aria-label="დასაბრუნებელი თანხა"
                                                name="amount"
                                                inputMode="decimal"
                                                required
                                                className="w-28"
                                                placeholder="0.00"
                                            />
                                            <Input
                                                aria-label="მიზეზი"
                                                name="reason"
                                                required
                                                minLength={5}
                                                placeholder="მიზეზი"
                                            />
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                type="submit"
                                            >
                                                დაბრუნება
                                            </Button>
                                        </form>
                                    </div>
                                )}
                            </div>
                        ))}
                    </section>

                    {invoice.status !== 'void' && invoice.paidMinor === 0 && (
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                postWithToast(
                                    `/portal/finance/invoices/${invoice.id}/void`,
                                    { reason: voidReason },
                                    'ინვოისი გაუქმდა.',
                                );
                            }}
                        >
                            <div className="min-w-[220px] flex-1">
                                <label
                                    htmlFor="void-reason"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    გაუქმების მიზეზი
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
                                ინვოისის გაუქმება
                            </Button>
                        </form>
                    )}
                </>
            )}
        </PortalLayout>
    );
}
