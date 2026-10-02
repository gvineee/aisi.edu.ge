import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Plus, Receipt } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import SandboxBanner from '@/components/portal/sandbox-banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';
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
    sandbox: boolean;
    needsMfa: boolean;
    staff: {
        invoices: InvoiceRow[];
        students: { id: number; name: string }[];
    } | null;
    children: {
        id: number;
        name: string;
        creditMinor: number;
        invoices: InvoiceRow[];
    }[];
};

const STATUS_LABELS: Record<string, string> = {
    issued: 'გადასახდელი',
    partially_paid: 'ნაწილობრივ გადახდილი',
    paid: 'გადახდილი',
    void: 'გაუქმებული',
};

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
                            {STATUS_LABELS[inv.status] ?? inv.status} ·{' '}
                            {formatMoney(inv.paidMinor, inv.currency)} /{' '}
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

/**
 * Finance overview. Guardians see only children whose link includes the
 * financial permission; staff need 2FA. Amounts are integer tetri,
 * displayed as GEL.
 */
export default function FinanceIndex({ needsMfa, staff, children }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [items, setItems] = useState([
        { description: '', quantity: '1', unit: '' },
    ]);

    const issue = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        postWithToast(
            '/portal/finance/invoices',
            {
                student_id: form.get('student_id'),
                due_on: form.get('due_on'),
                items,
            },
            'ინვოისი გამოიწერა.',
        );
    };

    return (
        <PortalLayout>
            <Head title="ფინანსები" />
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ფინანსები</h1>
                {staff && (
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href="/portal/finance/reconciliation"
                            className="inline-flex min-h-11 items-center rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                        >
                            შეჯერება
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
            <SandboxBanner />

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

            {staff && showForm && (
                <form
                    onSubmit={issue}
                    className="mb-6 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                >
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
                                className="grid grid-cols-[1fr_5rem_7rem] gap-2"
                            >
                                <Input
                                    aria-label="აღწერა"
                                    value={item.description}
                                    required
                                    onChange={(e) =>
                                        setItems((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          description:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                    placeholder="აღწერა"
                                />
                                <Input
                                    aria-label="რაოდენობა"
                                    type="number"
                                    min={1}
                                    value={item.quantity}
                                    onChange={(e) =>
                                        setItems((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          quantity:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                <Input
                                    aria-label="ერთეულის ფასი"
                                    inputMode="decimal"
                                    pattern="\d+(\.\d{1,2})?"
                                    required
                                    value={item.unit}
                                    onChange={(e) =>
                                        setItems((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          unit: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
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
                    <Button type="submit">გამოწერა</Button>
                </form>
            )}

            {children.map((child) => (
                <section
                    key={child.id}
                    className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                >
                    <h2 className="text-lg">{child.name}</h2>
                    {child.creditMinor > 0 && (
                        <p className="text-sm text-emerald-800">
                            ანგარიშის კრედიტი: {formatMoney(child.creditMinor)}
                        </p>
                    )}
                    <InvoiceList
                        invoices={child.invoices}
                        showStudent={false}
                        payable
                    />
                </section>
            ))}

            {staff && (
                <section
                    className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                    aria-labelledby="all-invoices"
                >
                    <h2
                        id="all-invoices"
                        className="mb-2 flex items-center gap-2 text-lg"
                    >
                        <Receipt size={18} aria-hidden /> ინვოისები
                    </h2>
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
