import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Download, Plus, Receipt, Search } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import SandboxBanner from '@/components/portal/sandbox-banner';
import FinanceNav from '@/components/finance/finance-nav';
import PaymentInstructions from '@/components/finance/payment-instructions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';
import { INVOICE_STATUS, MONEY_PATTERN, type PaymentInfo } from '@/lib/finance';
import { formatMoney, postWithToast } from '@/lib/operations';

type InvoiceRow = {
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
};

type Props = {
    onlinePayments: boolean;
    needsMfa: boolean;
    paymentInfo: PaymentInfo;
    staff: {
        filters: { status: string | null; q: string };
        summary: {
            outstandingMinor: number;
            overdueMinor: number;
            collectedThisMonthMinor: number;
            openInvoices: number;
        };
        hasPaymentInfo: boolean;
        invoices: InvoiceRow[];
        students: { id: number; name: string }[];
        billableClubFees: {
            clubId: number;
            clubName: string;
            studentId: number;
            studentName: string;
            feeMinor: number;
        }[];
    } | null;
    children: {
        id: number;
        name: string;
        creditMinor: number;
        outstandingMinor: number;
        overdueMinor: number;
        invoices: InvoiceRow[];
        payments: {
            id: number;
            receiptNumber: string;
            receivedOn: string;
            methodLabel: string;
            amountMinor: number;
            currency: string;
        }[];
    }[];
};

const FILTERS: { value: string; label: string }[] = [
    { value: '', label: 'ყველა' },
    { value: 'overdue', label: 'ვადაგადაცილებული' },
    { value: 'issued', label: 'გადასახდელი' },
    { value: 'partially_paid', label: 'ნაწილობრივ' },
    { value: 'paid', label: 'გადახდილი' },
    { value: 'void', label: 'გაუქმებული' },
];

function isOverdue(inv: InvoiceRow): boolean {
    const today = new Date().toISOString().slice(0, 10);

    return (
        inv.outstandingMinor > 0 && inv.status !== 'void' && inv.dueOn < today
    );
}

function InvoiceList({
    invoices,
    showStudent,
    payable,
}: {
    invoices: InvoiceRow[];
    showStudent: boolean;
    payable: boolean;
}) {
    if (invoices.length === 0) {
        return <p className="text-sm text-slate-500">ინვოისი არ არის.</p>;
    }

    return (
        <ul className="divide-y divide-slate-100">
            {invoices.map((inv) => (
                <li
                    key={inv.id}
                    className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                >
                    <Link
                        href={`/portal/finance/invoices/${inv.id}`}
                        className="min-w-0 underline-offset-4 hover:underline"
                    >
                        <strong>№ {inv.number}</strong>
                        {showStudent && inv.studentName
                            ? ` · ${inv.studentName}`
                            : ''}{' '}
                        · ვადა {formatDay(inv.dueOn)}
                        <span className="block text-slate-500">
                            {INVOICE_STATUS[inv.status] ?? inv.status}
                            {isOverdue(inv) && (
                                <span className="ml-1 font-medium text-red-700">
                                    · ვადაგადაცილებული
                                </span>
                            )}{' '}
                            · {formatMoney(inv.paidMinor, inv.currency)} /{' '}
                            {formatMoney(inv.totalMinor, inv.currency)}
                        </span>
                    </Link>
                    {payable &&
                        inv.outstandingMinor > 0 &&
                        inv.status !== 'void' && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/portal/finance/invoices/${inv.id}/pay`,
                                    )
                                }
                            >
                                გადახდა{' '}
                                {formatMoney(
                                    inv.outstandingMinor,
                                    inv.currency,
                                )}
                            </Button>
                        )}
                </li>
            ))}
        </ul>
    );
}

function Stat({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: string;
    tone?: 'default' | 'warn';
}) {
    return (
        <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
            <p className="text-xs text-slate-500">{label}</p>
            <p
                className={`mt-1 text-xl font-semibold ${tone === 'warn' ? 'text-red-700' : ''}`}
            >
                {value}
            </p>
        </div>
    );
}

/**
 * Finance overview. Staff (2FA) get the invoice register and the
 * bookkeeping sections; guardians see each child's balance, invoices,
 * payments and how to pay. Amounts are integer tetri shown as GEL.
 */
export default function FinanceIndex({
    onlinePayments,
    needsMfa,
    paymentInfo,
    staff,
    children,
}: Props) {
    const [showForm, setShowForm] = useState(false);
    const [items, setItems] = useState([
        { description: '', quantity: '1', unit: '' },
    ]);
    const [search, setSearch] = useState(staff?.filters.q ?? '');

    const issue = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        postWithToast(
            '/portal/finance/invoices',
            {
                student_id: form.get('student_id'),
                due_on: form.get('due_on'),
                items: items.map((item) => ({
                    ...item,
                    unit: item.unit.replace(',', '.'),
                })),
            },
            'ინვოისი გამოიწერა.',
        );
    };

    const filter = (status: string | null, q: string) =>
        router.get(
            '/portal/finance',
            { ...(status ? { status } : {}), ...(q ? { q } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const updateItem = (index: number, key: 'description' | 'quantity' | 'unit', value: string) =>
        setItems((all) =>
            all.map((x, j) => (j === index ? { ...x, [key]: value } : x)),
        );

    return (
        <PortalLayout>
            <Head title="ფინანსები" />
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ფინანსები</h1>
                {staff && (
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href="/portal/finance/payments"
                            className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm text-white hover:bg-slate-800"
                        >
                            გადახდის გატარება
                        </Link>
                        <Button
                            variant="outline"
                            onClick={() => setShowForm((v) => !v)}
                            aria-expanded={showForm}
                        >
                            <Plus size={16} /> ინვოისი
                        </Button>
                    </div>
                )}
            </div>
            {staff && <FinanceNav current="/portal/finance" />}
            {onlinePayments && <SandboxBanner />}

            {needsMfa && (
                <p
                    role="alert"
                    className="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    ფინანსურ მონაცემებზე წვდომისთვის ჩართეთ ორფაქტორიანი
                    ავთენტიფიკაცია (
                    <Link href="/settings/security" className="underline">
                        უსაფრთხოება
                    </Link>
                    ).
                </p>
            )}

            {staff && (
                <>
                    <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Stat
                            label="ღია დავალიანება"
                            value={formatMoney(staff.summary.outstandingMinor)}
                        />
                        <Stat
                            label="ვადაგადაცილებული"
                            value={formatMoney(staff.summary.overdueMinor)}
                            tone={
                                staff.summary.overdueMinor > 0
                                    ? 'warn'
                                    : 'default'
                            }
                        />
                        <Stat
                            label="მიღებული ამ თვეში"
                            value={formatMoney(
                                staff.summary.collectedThisMonthMinor,
                            )}
                        />
                        <Stat
                            label="ღია ინვოისები"
                            value={String(staff.summary.openInvoices)}
                        />
                    </div>
                    {!staff.hasPaymentInfo && (
                        <p className="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            მშობლებს გადახდის რეკვიზიტები ჯერ არ უჩანთ —{' '}
                            <Link
                                href="/portal/finance/settings"
                                className="underline"
                            >
                                შეავსეთ რეკვიზიტები
                            </Link>
                            .
                        </p>
                    )}
                </>
            )}

            {staff && showForm && (
                <form
                    onSubmit={issue}
                    className="mb-6 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                >
                    <h2 className="text-lg">ახალი ინვოისი</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label
                                htmlFor="inv-student"
                                className="mb-1 block text-sm font-medium"
                            >
                                მოსწავლე
                            </label>
                            <select
                                id="inv-student"
                                name="student_id"
                                required
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {staff.students.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label
                                htmlFor="inv-due"
                                className="mb-1 block text-sm font-medium"
                            >
                                გადახდის ვადა
                            </label>
                            <Input
                                id="inv-due"
                                name="due_on"
                                type="date"
                                required
                            />
                        </div>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            პოზიციები (ფასი ₾)
                        </legend>
                        {items.map((item, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-[1fr_4.5rem_6.5rem] gap-2"
                            >
                                <Input
                                    aria-label={`აღწერა ${i + 1}`}
                                    value={item.description}
                                    required
                                    onChange={(e) =>
                                        updateItem(i, 'description', e.target.value)
                                    }
                                    placeholder="აღწერა"
                                />
                                <Input
                                    aria-label={`რაოდენობა ${i + 1}`}
                                    type="number"
                                    min={1}
                                    value={item.quantity}
                                    onChange={(e) =>
                                        updateItem(i, 'quantity', e.target.value)
                                    }
                                />
                                <Input
                                    aria-label={`ერთეულის ფასი ${i + 1}`}
                                    inputMode="decimal"
                                    pattern={MONEY_PATTERN}
                                    required
                                    value={item.unit}
                                    onChange={(e) =>
                                        updateItem(i, 'unit', e.target.value)
                                    }
                                    placeholder="0.00"
                                />
                            </div>
                        ))}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setItems((all) => [
                                    ...all,
                                    {
                                        description: '',
                                        quantity: '1',
                                        unit: '',
                                    },
                                ])
                            }
                        >
                            + პოზიცია
                        </Button>
                    </fieldset>
                    <p className="text-xs text-slate-500">
                        მშობელს, რომელსაც ფინანსებზე წვდომა და შეტყობინებები
                        აქვს, ელფოსტით ეცნობება.
                    </p>
                    <Button type="submit">გამოწერა</Button>
                </form>
            )}

            {children.length > 0 && (
                <PaymentInstructions info={paymentInfo} />
            )}

            {children.map((child) => (
                <section
                    key={child.id}
                    className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                    aria-labelledby={`child-${child.id}`}
                >
                    <h2 id={`child-${child.id}`} className="text-lg">
                        {child.name}
                    </h2>
                    <dl className="my-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-slate-500">გადასახდელი</dt>
                            <dd className="text-lg font-semibold">
                                {formatMoney(child.outstandingMinor)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">ვადაგადაცილებული</dt>
                            <dd
                                className={`text-lg font-semibold ${child.overdueMinor > 0 ? 'text-red-700' : ''}`}
                            >
                                {formatMoney(child.overdueMinor)}
                            </dd>
                        </div>
                        {child.creditMinor > 0 && (
                            <div>
                                <dt className="text-slate-500">
                                    ზედმეტად გადახდილი (კრედიტი)
                                </dt>
                                <dd className="text-lg font-semibold text-emerald-800">
                                    {formatMoney(child.creditMinor)}
                                </dd>
                            </div>
                        )}
                    </dl>
                    <h3 className="mt-4 text-base font-medium">ინვოისები</h3>
                    <InvoiceList
                        invoices={child.invoices}
                        showStudent={false}
                        payable={onlinePayments}
                    />
                    <h3 className="mt-4 text-base font-medium">
                        მიღებული გადახდები
                    </h3>
                    {child.payments.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            გადახდა ჯერ არ აღრიცხულა.
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-100 text-sm">
                            {child.payments.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-2"
                                >
                                    <span>
                                        {formatDay(p.receivedOn)} ·{' '}
                                        {p.methodLabel} ·{' '}
                                        <strong>
                                            {formatMoney(
                                                p.amountMinor,
                                                p.currency,
                                            )}
                                        </strong>
                                    </span>
                                    <a
                                        href={`/portal/finance/payments/${p.id}/receipt`}
                                        className="inline-flex min-h-11 items-center gap-1 text-slate-700 underline-offset-4 hover:underline"
                                    >
                                        <Download size={14} aria-hidden />{' '}
                                        ქვითარი {p.receiptNumber}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ))}

            {staff && staff.billableClubFees.length > 0 && (
                <section
                    className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                    aria-labelledby="club-fees"
                >
                    <h2 id="club-fees" className="mb-1 text-lg">
                        გამოუწერელი კლუბის საფასური
                    </h2>
                    <p className="mb-3 text-sm text-slate-500">
                        თითო ჩარიცხვაზე ერთი ინვოისი; განმეორებითი ბილინგი
                        სკოლის საფასურის წესის დადგენამდე არ ხდება.
                    </p>
                    <ul className="divide-y divide-slate-100">
                        {staff.billableClubFees.map((fee) => (
                            <li
                                key={`${fee.clubId}-${fee.studentId}`}
                                className="py-3 text-sm"
                            >
                                <form
                                    className="flex flex-wrap items-center gap-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        const form = new FormData(
                                            event.currentTarget,
                                        );
                                        postWithToast(
                                            `/portal/finance/clubs/${fee.clubId}/invoice`,
                                            {
                                                student_id: fee.studentId,
                                                due_on: form.get('due_on'),
                                            },
                                            'ინვოისი გამოიწერა.',
                                        );
                                    }}
                                >
                                    <span className="min-w-0 flex-1">
                                        <strong>{fee.studentName}</strong> ·{' '}
                                        {fee.clubName} ·{' '}
                                        {formatMoney(fee.feeMinor)}
                                    </span>
                                    <label
                                        className="sr-only"
                                        htmlFor={`due-${fee.clubId}-${fee.studentId}`}
                                    >
                                        გადახდის ვადა
                                    </label>
                                    <Input
                                        id={`due-${fee.clubId}-${fee.studentId}`}
                                        name="due_on"
                                        type="date"
                                        required
                                        className="w-auto"
                                    />
                                    <Button type="submit" size="sm">
                                        ინვოისის გამოწერა
                                    </Button>
                                </form>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {staff && (
                <section
                    className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                    aria-labelledby="all-invoices"
                >
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2
                            id="all-invoices"
                            className="flex items-center gap-2 text-lg"
                        >
                            <Receipt size={18} aria-hidden /> ინვოისები
                        </h2>
                        <a
                            href="/portal/finance/exports/invoices"
                            className="inline-flex min-h-11 items-center gap-1 text-sm text-slate-700 underline-offset-4 hover:underline"
                        >
                            <Download size={14} aria-hidden /> Excel (ამ თვის)
                        </a>
                    </div>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <label htmlFor="inv-filter" className="sr-only">
                            სტატუსი
                        </label>
                        <select
                            id="inv-filter"
                            value={staff.filters.status ?? ''}
                            onChange={(e) =>
                                filter(e.target.value || null, search)
                            }
                            className="h-11 rounded-md border border-slate-300 bg-white px-3 text-sm"
                        >
                            {FILTERS.map((f) => (
                                <option key={f.value} value={f.value}>
                                    {f.label}
                                </option>
                            ))}
                        </select>
                        <form
                            role="search"
                            className="flex min-w-0 flex-1 gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                filter(staff.filters.status, search);
                            }}
                        >
                            <label htmlFor="inv-search" className="sr-only">
                                ძებნა ნომრით ან მოსწავლით
                            </label>
                            <Input
                                id="inv-search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ნომერი ან მოსწავლე"
                                className="min-w-0 flex-1"
                            />
                            <Button type="submit" variant="outline" aria-label="ძებნა">
                                <Search size={16} />
                            </Button>
                        </form>
                    </div>
                    <InvoiceList
                        invoices={staff.invoices}
                        showStudent
                        payable={false}
                    />
                </section>
            )}
        </PortalLayout>
    );
}
