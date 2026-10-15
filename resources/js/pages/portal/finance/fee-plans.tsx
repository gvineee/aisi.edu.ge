import { Head, Link } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Plus } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import FinanceNav from '@/components/finance/finance-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';
import { MONEY_PATTERN, todayTbilisi } from '@/lib/finance';
import { formatMoney, postWithToast } from '@/lib/operations';

type Contract = {
    id: number;
    studentId: number;
    studentName: string | null;
    className: string | null;
    status: string;
    discountPercent: number;
    discountReason: string | null;
    contractNumber: string | null;
    signedOn: string | null;
    endedOn: string | null;
};

type Plan = {
    id: number;
    name: string;
    isActive: boolean;
    academicYearName: string | null;
    totalMinor: number;
    activeContracts: number;
    installments: {
        id: number;
        label: string;
        amountMinor: number;
        issueOn: string;
        dueOn: string;
        billed: number;
    }[];
    contracts: Contract[];
};

type Props = {
    plans: Plan[];
    pending: {
        invoices: number;
        waived: number;
        totalMinor: number;
        skippedInactive: number;
        rows: { student: string; installment: string; amountMinor: number }[];
    };
    academicYears: { id: number; name: string }[];
    students: { id: number; name: string; className: string | null }[];
};

const fieldClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

const blankInstallment = () => ({ label: '', amount: '', issue_on: '', due_on: '' });

function NewPlanForm({
    academicYears,
    onDone,
}: {
    academicYears: Props['academicYears'];
    onDone: () => void;
}) {
    const [rows, setRows] = useState([blankInstallment()]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        postWithToast(
            '/portal/finance/fee-plans',
            {
                name: form.get('name'),
                academic_year_id: form.get('academic_year_id') || null,
                installments: rows,
            },
            'გეგმა შეიქმნა.',
            onDone,
        );
    };

    const update = (i: number, key: keyof ReturnType<typeof blankInstallment>, value: string) =>
        setRows((all) => all.map((r, j) => (j === i ? { ...r, [key]: value } : r)));

    return (
        <form
            onSubmit={submit}
            className="mb-6 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
        >
            <h2 className="text-lg">ახალი საფასურის გეგმა</h2>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor="fp-name" className="mb-1 block text-sm font-medium">
                        დასახელება
                    </label>
                    <Input id="fp-name" name="name" required maxLength={255} placeholder="მაგ. სწავლის საფასური 2026–2027" />
                </div>
                <div>
                    <label htmlFor="fp-year" className="mb-1 block text-sm font-medium">
                        სასწავლო წელი
                    </label>
                    <select id="fp-year" name="academic_year_id" className={fieldClass}>
                        <option value="">—</option>
                        {academicYears.map((y) => (
                            <option key={y.id} value={y.id}>
                                {y.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <fieldset className="space-y-3">
                <legend className="text-sm font-medium">შენატანები</legend>
                {rows.map((row, i) => (
                    <div key={i} className="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-4">
                        <div>
                            <label htmlFor={`fi-l-${i}`} className="mb-1 block text-xs">
                                დასახელება
                            </label>
                            <Input id={`fi-l-${i}`} value={row.label} required onChange={(e) => update(i, 'label', e.target.value)} placeholder="I შენატანი" />
                        </div>
                        <div>
                            <label htmlFor={`fi-a-${i}`} className="mb-1 block text-xs">
                                თანხა (₾)
                            </label>
                            <Input id={`fi-a-${i}`} value={row.amount} required inputMode="decimal" pattern={MONEY_PATTERN} onChange={(e) => update(i, 'amount', e.target.value)} />
                        </div>
                        <div>
                            <label htmlFor={`fi-i-${i}`} className="mb-1 block text-xs">
                                ინვოისის გამოწერა
                            </label>
                            <Input id={`fi-i-${i}`} type="date" value={row.issue_on} required onChange={(e) => update(i, 'issue_on', e.target.value)} />
                        </div>
                        <div>
                            <label htmlFor={`fi-d-${i}`} className="mb-1 block text-xs">
                                გადახდის ვადა
                            </label>
                            <Input id={`fi-d-${i}`} type="date" value={row.due_on} required min={row.issue_on || undefined} onChange={(e) => update(i, 'due_on', e.target.value)} />
                        </div>
                    </div>
                ))}
                <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" variant="outline" onClick={() => setRows((all) => [...all, blankInstallment()])}>
                        + შენატანი
                    </Button>
                    {rows.length > 1 && (
                        <Button type="button" size="sm" variant="outline" onClick={() => setRows((all) => all.slice(0, -1))}>
                            ბოლოს წაშლა
                        </Button>
                    )}
                </div>
            </fieldset>
            <p className="text-xs text-slate-500">
                ინვოისები ავტომატურად გამოიწერება გამოწერის თარიღზე (ყოველ
                დილით) ყველა აქტიური ხელშეკრულებისთვის; მშობლებს ელფოსტით
                ეცნობებათ.
            </p>
            <Button type="submit">შენახვა</Button>
        </form>
    );
}

function ContractForm({ plan, students }: { plan: Plan; students: Props['students'] }) {
    const [discount, setDiscount] = useState('0');
    const taken = new Set(plan.contracts.filter((c) => c.status === 'active').map((c) => c.studentId));

    return (
        <form
            className="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-3"
            onSubmit={(e) => {
                e.preventDefault();
                const form = e.currentTarget;
                postWithToast(`/portal/finance/fee-plans/${plan.id}/contracts`, new FormData(form), 'ხელშეკრულება დაემატა.', () => {
                    form.reset();
                    setDiscount('0');
                });
            }}
        >
            <div className="sm:col-span-3">
                <label htmlFor={`c-s-${plan.id}`} className="mb-1 block text-xs font-medium">
                    მოსწავლე
                </label>
                <select id={`c-s-${plan.id}`} name="student_id" required className={fieldClass}>
                    <option value="">— აირჩიეთ —</option>
                    {students
                        .filter((s) => !taken.has(s.id))
                        .map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                                {s.className ? ` · ${s.className}` : ''}
                            </option>
                        ))}
                </select>
            </div>
            <div>
                <label htmlFor={`c-n-${plan.id}`} className="mb-1 block text-xs">
                    ხელშეკრულების №
                </label>
                <Input id={`c-n-${plan.id}`} name="contract_number" maxLength={64} />
            </div>
            <div>
                <label htmlFor={`c-d-${plan.id}`} className="mb-1 block text-xs">
                    ხელმოწერის თარიღი
                </label>
                <Input id={`c-d-${plan.id}`} name="signed_on" type="date" max={todayTbilisi()} />
            </div>
            <div>
                <label htmlFor={`c-p-${plan.id}`} className="mb-1 block text-xs">
                    ფასდაკლება (%)
                </label>
                <Input id={`c-p-${plan.id}`} name="discount_percent" type="number" min={0} max={100} value={discount} onChange={(e) => setDiscount(e.target.value)} />
            </div>
            {Number(discount) > 0 && (
                <div className="sm:col-span-3">
                    <label htmlFor={`c-r-${plan.id}`} className="mb-1 block text-xs">
                        ფასდაკლების საფუძველი
                    </label>
                    <Input id={`c-r-${plan.id}`} name="discount_reason" required minLength={3} maxLength={255} />
                </div>
            )}
            <div className="sm:col-span-3">
                <Button type="submit" size="sm">
                    ხელშეკრულების დამატება
                </Button>
            </div>
        </form>
    );
}

/**
 * Fee plans with installments, the students contracted on each, and
 * invoice generation. Generation never bills the same installment twice.
 */
export default function FinanceFeePlans({ plans, pending, academicYears, students }: Props) {
    const [showNew, setShowNew] = useState(plans.length === 0);

    return (
        <PortalLayout>
            <Head title="საფასური და ხელშეკრულებები" />
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">საფასური და ხელშეკრულებები</h1>
                <Button variant="outline" onClick={() => setShowNew((v) => !v)} aria-expanded={showNew}>
                    <Plus size={16} /> ახალი გეგმა
                </Button>
            </div>
            <FinanceNav current="/portal/finance/fee-plans" />

            <section className="mb-6 rounded-xl bg-white p-5 text-sm ring-1 ring-slate-200" aria-labelledby="pending-title">
                <h2 id="pending-title" className="mb-1 text-lg">
                    გამოსაწერი ახლა
                </h2>
                {pending.invoices + pending.waived === 0 ? (
                    <p className="text-slate-500">ვადამოსული გამოუწერელი შენატანი არ არის.</p>
                ) : (
                    <>
                        <p>
                            {pending.invoices} ინვოისი · {formatMoney(pending.totalMinor)}
                            {pending.waived > 0 && ` · ${pending.waived} სრულად შეღავათიანი`}
                        </p>
                        <details className="my-2">
                            <summary className="min-h-11 cursor-pointer py-2">ჩამონათვალი</summary>
                            <ul className="space-y-1 text-slate-600">
                                {pending.rows.map((r, i) => (
                                    <li key={i}>
                                        {r.student} · {r.installment} · {formatMoney(r.amountMinor)}
                                    </li>
                                ))}
                            </ul>
                        </details>
                        <Button onClick={() => postWithToast('/portal/finance/fee-plans/generate', {}, 'ინვოისები გამოიწერა.')}>
                            ახლავე გამოწერა
                        </Button>
                    </>
                )}
                {pending.skippedInactive > 0 && (
                    <p className="mt-2 text-amber-800">
                        {pending.skippedInactive} ხელშეკრულება ეკუთვნის არააქტიურ მოსწავლეს და არ გამოიწერება — დაასრულეთ ხელშეკრულება.
                    </p>
                )}
            </section>

            {showNew && <NewPlanForm academicYears={academicYears} onDone={() => setShowNew(false)} />}

            {plans.length === 0 && !showNew && (
                <p className="rounded-xl bg-white p-5 text-sm text-slate-500 ring-1 ring-slate-200">
                    საფასურის გეგმა ჯერ არ არის. თანხებსა და ვადებს სკოლა თავად აწესებს.
                </p>
            )}

            <div className="space-y-6">
                {plans.map((plan) => (
                    <section key={plan.id} className="rounded-xl bg-white p-5 ring-1 ring-slate-200" aria-labelledby={`plan-${plan.id}`}>
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h2 id={`plan-${plan.id}`} className="text-lg">
                                    {plan.name}
                                    {!plan.isActive && <span className="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">გამორთული</span>}
                                </h2>
                                <p className="text-sm text-slate-500">
                                    {plan.academicYearName ? `${plan.academicYearName} · ` : ''}
                                    სულ {formatMoney(plan.totalMinor)} · {plan.activeContracts} აქტიური ხელშეკრულება
                                </p>
                            </div>
                            <Button size="sm" variant="outline" onClick={() => postWithToast(`/portal/finance/fee-plans/${plan.id}/toggle`, {}, plan.isActive ? 'გეგმა გამოირთო.' : 'გეგმა ჩაირთო.')}>
                                {plan.isActive ? 'ავტოგამოწერის გამორთვა' : 'ჩართვა'}
                            </Button>
                        </div>

                        <ul className="mt-3 divide-y divide-slate-100 text-sm">
                            {plan.installments.map((inst) => (
                                <li key={inst.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>
                                        <strong>{inst.label}</strong> · {formatMoney(inst.amountMinor)} · გამოწერა {formatDay(inst.issueOn)} · ვადა {formatDay(inst.dueOn)}
                                        <span className="block text-slate-500">
                                            გამოწერილია {inst.billed}/{plan.activeContracts}
                                        </span>
                                    </span>
                                    {plan.isActive && inst.billed < plan.activeContracts && (
                                        <Button size="sm" variant="outline" onClick={() => postWithToast('/portal/finance/fee-plans/generate', { installment_id: inst.id }, 'გამოიწერა.')}>
                                            ახლავე გამოწერა
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>

                        <h3 className="mt-4 mb-2 text-base font-medium">ხელშეკრულებები</h3>
                        {plan.contracts.length === 0 ? (
                            <p className="mb-3 text-sm text-slate-500">ჯერ არცერთი მოსწავლე.</p>
                        ) : (
                            <ul className="mb-3 divide-y divide-slate-100 text-sm">
                                {plan.contracts.map((c) => (
                                    <li key={c.id} className={`flex flex-wrap items-center justify-between gap-2 py-2 ${c.status === 'ended' ? 'opacity-60' : ''}`}>
                                        <span>
                                            <Link href={`/portal/finance/accounts/${c.studentId}`} className="underline-offset-4 hover:underline">
                                                {c.studentName}
                                            </Link>
                                            {c.className ? ` · ${c.className}` : ''}
                                            {c.contractNumber ? ` · № ${c.contractNumber}` : ''}
                                            {c.discountPercent > 0 && ` · ფასდაკლება ${c.discountPercent}% (${c.discountReason})`}
                                            {c.status === 'ended' && c.endedOn && ` · დასრულდა ${formatDay(c.endedOn)}`}
                                        </span>
                                        {c.status === 'active' && (
                                            <form
                                                className="flex items-center gap-2"
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    postWithToast(`/portal/finance/fee-contracts/${c.id}/end`, new FormData(e.currentTarget), 'ხელშეკრულება დასრულდა.');
                                                }}
                                            >
                                                <label htmlFor={`end-${c.id}`} className="sr-only">
                                                    დასრულების თარიღი
                                                </label>
                                                <Input id={`end-${c.id}`} name="ended_on" type="date" required defaultValue={todayTbilisi()} className="w-auto" />
                                                <Button size="sm" variant="outline" type="submit">
                                                    დასრულება
                                                </Button>
                                            </form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <ContractForm plan={plan} students={students} />
                    </section>
                ))}
            </div>
        </PortalLayout>
    );
}
