import { Head, Link, router } from '@inertiajs/react';
import { Download, Mail } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import FinanceNav from '@/components/finance/finance-nav';
import { Button } from '@/components/ui/button';
import { formatDay } from '@/lib/assessment';
import { formatMoney, postWithToast } from '@/lib/operations';

type Row = {
    accountId: number;
    studentId: number | null;
    studentName: string | null;
    className: string | null;
    currency: string;
    openInvoices: number;
    outstandingMinor: number;
    overdueMinor: number;
    oldestDueOn: string;
    daysOverdue: number;
    creditMinor: number;
    guardians: { name: string; email: string }[];
};

type Props = {
    filters: { class: number | null; overdue: boolean };
    classes: { id: number; name: string }[];
    rows: Row[];
    totals: { outstandingMinor: number; overdueMinor: number; students: number };
};

const fieldClass =
    'h-11 rounded-md border border-slate-300 bg-white px-3 text-sm';

/**
 * Who owes what: one card per student with an open balance, overdue first.
 * Reminders go only to guardians with financial access and notifications
 * on, at most once every three days per invoice.
 */
export default function FinanceDebtors({ filters, classes, rows, totals }: Props) {
    const query = (next: { class?: number | null; overdue?: boolean }) => {
        const merged = { ...filters, ...next };
        router.get(
            '/portal/finance/debtors',
            {
                ...(merged.class ? { class: merged.class } : {}),
                ...(merged.overdue ? { overdue: 1 } : {}),
            },
            { preserveScroll: true, replace: true },
        );
    };

    const exportHref = `/portal/finance/exports/debtors?${new URLSearchParams({
        ...(filters.class ? { class: String(filters.class) } : {}),
        ...(filters.overdue ? { overdue: '1' } : {}),
    }).toString()}`;

    return (
        <PortalLayout>
            <Head title="დავალიანებები" />
            <h1 className="mb-4 text-2xl">დავალიანებები</h1>
            <FinanceNav current="/portal/finance/debtors" />

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label
                        htmlFor="d-class"
                        className="mb-1 block text-xs font-medium"
                    >
                        კლასი
                    </label>
                    <select
                        id="d-class"
                        value={filters.class ?? ''}
                        onChange={(e) =>
                            query({
                                class: e.target.value
                                    ? Number(e.target.value)
                                    : null,
                            })
                        }
                        className={fieldClass}
                    >
                        <option value="">ყველა</option>
                        {classes.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>
                <label className="inline-flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={filters.overdue}
                        onChange={(e) => query({ overdue: e.target.checked })}
                        className="size-5"
                    />
                    მხოლოდ ვადაგადაცილებული
                </label>
                <a
                    href={exportHref}
                    className="inline-flex min-h-11 items-center gap-1 rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                >
                    <Download size={14} aria-hidden /> Excel
                </a>
                <Button
                    variant="outline"
                    onClick={() =>
                        postWithToast(
                            '/portal/finance/reminders/overdue',
                            {},
                            'შეხსენებები გაიგზავნა.',
                        )
                    }
                >
                    <Mail size={16} /> შეხსენება ყველა ვადაგადაცილებულს
                </Button>
            </div>

            <p className="mb-4 text-sm text-slate-600">
                {totals.students} მოსწავლე · დავალიანება{' '}
                <strong>{formatMoney(totals.outstandingMinor)}</strong> ·
                ვადაგადაცილებული{' '}
                <strong className="text-red-700">
                    {formatMoney(totals.overdueMinor)}
                </strong>
            </p>

            {rows.length === 0 ? (
                <p className="rounded-xl bg-white p-5 text-sm text-slate-500 ring-1 ring-slate-200">
                    დავალიანება არ არის.
                </p>
            ) : (
                <ul className="grid gap-3 md:grid-cols-2">
                    {rows.map((row) => (
                        <li
                            key={row.accountId}
                            className="rounded-xl bg-white p-4 text-sm ring-1 ring-slate-200"
                        >
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                {row.studentId ? (
                                    <Link
                                        href={`/portal/finance/accounts/${row.studentId}`}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.studentName}
                                    </Link>
                                ) : (
                                    <span className="font-medium">
                                        {row.studentName}
                                    </span>
                                )}
                                <span className="text-slate-500">
                                    {row.className}
                                </span>
                            </div>
                            <dl className="mt-2 grid grid-cols-2 gap-2">
                                <div>
                                    <dt className="text-xs text-slate-500">
                                        დავალიანება
                                    </dt>
                                    <dd className="font-semibold">
                                        {formatMoney(
                                            row.outstandingMinor,
                                            row.currency,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-slate-500">
                                        ვადაგადაცილებული
                                    </dt>
                                    <dd
                                        className={`font-semibold ${row.overdueMinor > 0 ? 'text-red-700' : ''}`}
                                    >
                                        {formatMoney(
                                            row.overdueMinor,
                                            row.currency,
                                        )}
                                        {row.daysOverdue > 0 &&
                                            ` · ${row.daysOverdue} დღე`}
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-2 text-slate-500">
                                {row.openInvoices} ღია ინვოისი · უძველესი ვადა{' '}
                                {formatDay(row.oldestDueOn)}
                                {row.creditMinor > 0 &&
                                    ` · კრედიტი ${formatMoney(row.creditMinor)}`}
                            </p>
                            <p className="mt-1 text-slate-600">
                                {row.guardians.length === 0
                                    ? 'ფინანსური წვდომის მქონე მშობელი არ არის მიბმული.'
                                    : row.guardians
                                          .map((g) => `${g.name} (${g.email})`)
                                          .join(', ')}
                            </p>
                            {row.studentId && (
                                <Link
                                    href={`/portal/finance/payments?student=${row.studentId}`}
                                    className="mt-2 inline-flex min-h-11 items-center text-slate-800 underline underline-offset-4"
                                >
                                    გადახდის გატარება
                                </Link>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
