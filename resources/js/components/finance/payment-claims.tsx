import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';

export type PaymentClaimRow = {
    id: number;
    invoiceId: number;
    amountMinor: number;
    method: 'bank_transfer' | 'cash';
    paidOn: string;
    reference: string | null;
    note: string | null;
    status: 'pending' | 'confirmed' | 'rejected';
    reviewNote: string | null;
    submittedAt: string | null;
    submittedBy: string | null;
    invoiceNumber?: string;
    studentName?: string | null;
};

const STATUS: Record<
    PaymentClaimRow['status'],
    { label: string; className: string }
> = {
    pending: {
        label: 'ბუღალტერიაში განიხილება',
        className: 'bg-amber-100 text-amber-900',
    },
    confirmed: {
        label: 'დადასტურებულია',
        className: 'bg-emerald-100 text-emerald-800',
    },
    rejected: { label: 'უარყოფილია', className: 'bg-slate-200 text-slate-700' },
};

const METHOD: Record<PaymentClaimRow['method'], string> = {
    bank_transfer: 'საბანკო გადარიცხვა',
    cash: 'ნაღდი, სკოლაში',
};

const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

/** One notice, with the accountant's confirm/reject controls when allowed. */
export function PaymentClaimItem({
    claim,
    money,
    canReview,
}: {
    claim: PaymentClaimRow;
    money: (minor: number) => string;
    canReview: boolean;
}) {
    const [amount, setAmount] = useState((claim.amountMinor / 100).toFixed(2));
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const done = {
        preserveScroll: true,
        onError,
        onFinish: () => setBusy(false),
    };

    return (
        <li className="rounded-lg border border-slate-200 bg-white p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {money(claim.amountMinor)} · {METHOD[claim.method]} ·{' '}
                        {formatDay(claim.paidOn)}
                    </p>
                    {claim.invoiceNumber && (
                        <p className="text-slate-600">
                            ინვოისი № {claim.invoiceNumber}
                            {claim.studentName ? `, ${claim.studentName}` : ''}
                        </p>
                    )}
                    {claim.reference && (
                        <p className="text-slate-600">
                            ნომერი: {claim.reference}
                        </p>
                    )}
                    {claim.note && (
                        <p className="text-slate-600">{claim.note}</p>
                    )}
                    {claim.submittedBy && (
                        <p className="text-xs text-slate-500">
                            გამომგზავნი: {claim.submittedBy}
                        </p>
                    )}
                    {claim.reviewNote && (
                        <p className="mt-1 text-slate-700">
                            ბუღალტერია: {claim.reviewNote}
                        </p>
                    )}
                </div>
                <span
                    className={`rounded-full px-3 py-1 text-xs font-medium ${STATUS[claim.status].className}`}
                >
                    {STATUS[claim.status].label}
                </span>
            </div>

            {canReview && claim.status === 'pending' && (
                <div className="mt-3 grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2">
                    <div className="flex items-end gap-2">
                        <div className="flex-1">
                            <label
                                htmlFor={`claim-amount-${claim.id}`}
                                className="mb-1 block text-xs font-medium text-slate-600"
                            >
                                თანხა ამონაწერით (₾)
                            </label>
                            <Input
                                id={`claim-amount-${claim.id}`}
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                            />
                        </div>
                        <Button
                            disabled={busy}
                            onClick={() => {
                                setBusy(true);
                                router.post(
                                    `/portal/finance/claims/${claim.id}/confirm`,
                                    { amount },
                                    done,
                                );
                            }}
                        >
                            დადასტურება
                        </Button>
                    </div>
                    <div className="flex items-end gap-2">
                        <div className="flex-1">
                            <label
                                htmlFor={`claim-reason-${claim.id}`}
                                className="mb-1 block text-xs font-medium text-slate-600"
                            >
                                უარყოფის მიზეზი
                            </label>
                            <Input
                                id={`claim-reason-${claim.id}`}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                maxLength={500}
                            />
                        </div>
                        <Button
                            variant="outline"
                            disabled={busy || reason.trim() === ''}
                            onClick={() => {
                                setBusy(true);
                                router.post(
                                    `/portal/finance/claims/${claim.id}/reject`,
                                    { review_note: reason },
                                    done,
                                );
                            }}
                        >
                            უარყოფა
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}

/**
 * On an invoice: the guardian's "I have paid" form (no bank integration —
 * the accountant confirms it against the statement) and the notices so far.
 */
export default function PaymentClaims({
    invoiceId,
    outstandingMinor,
    claims,
    money,
    canSubmit,
    canReview,
    today,
}: {
    invoiceId: number;
    outstandingMinor: number;
    claims: PaymentClaimRow[];
    money: (minor: number) => string;
    canSubmit: boolean;
    canReview: boolean;
    today: string;
}) {
    const [sending, setSending] = useState(false);
    const [method, setMethod] =
        useState<PaymentClaimRow['method']>('bank_transfer');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        setSending(true);
        router.post(
            `/portal/finance/invoices/${invoiceId}/claims`,
            new FormData(target),
            {
                preserveScroll: true,
                onSuccess: () => target.reset(),
                onError,
                onFinish: () => setSending(false),
            },
        );
    };

    if (!canSubmit && claims.length === 0) {
        return null;
    }

    return (
        <section className="mb-6" aria-labelledby="payment-claims">
            <h2 id="payment-claims" className="mb-2 text-lg">
                {canReview ? 'მშობლის შეტყობინებები გადახდაზე' : 'გადავიხადე'}
            </h2>

            {canSubmit && (
                <form
                    onSubmit={submit}
                    className="mb-4 space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
                >
                    <p className="text-sm text-slate-600">
                        გადაიხადეთ ზემოთ მითითებულ ანგარიშზე ან სკოლის სალაროში
                        და შეატყობინეთ ბუღალტერიას. ინვოისი დაიხურება მას
                        შემდეგ, რაც ბუღალტერია გადახდას ამონაწერთან შეამოწმებს.
                    </p>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label
                                htmlFor="claim-amount"
                                className="mb-1 block text-sm font-medium"
                            >
                                თანხა (₾)
                            </label>
                            <Input
                                id="claim-amount"
                                name="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                max={(outstandingMinor / 100).toFixed(2)}
                                defaultValue={(outstandingMinor / 100).toFixed(
                                    2,
                                )}
                                required
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="claim-method"
                                className="mb-1 block text-sm font-medium"
                            >
                                როგორ
                            </label>
                            <select
                                id="claim-method"
                                name="method"
                                value={method}
                                onChange={(e) =>
                                    setMethod(
                                        e.target
                                            .value as PaymentClaimRow['method'],
                                    )
                                }
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                <option value="bank_transfer">
                                    {METHOD.bank_transfer}
                                </option>
                                <option value="cash">{METHOD.cash}</option>
                            </select>
                        </div>
                        <div>
                            <label
                                htmlFor="claim-date"
                                className="mb-1 block text-sm font-medium"
                            >
                                გადახდის დღე
                            </label>
                            <Input
                                id="claim-date"
                                name="paid_on"
                                type="date"
                                max={today}
                                defaultValue={today}
                                required
                            />
                        </div>
                    </div>
                    {method === 'bank_transfer' && (
                        <div>
                            <label
                                htmlFor="claim-reference"
                                className="mb-1 block text-sm font-medium"
                            >
                                გადარიცხვის ნომერი (ქვითრიდან ან ბანკის
                                აპლიკაციიდან)
                            </label>
                            <Input
                                id="claim-reference"
                                name="reference"
                                maxLength={120}
                                required
                            />
                        </div>
                    )}
                    <div>
                        <label
                            htmlFor="claim-note"
                            className="mb-1 block text-sm font-medium"
                        >
                            შენიშვნა (არასავალდებულო)
                        </label>
                        <Input id="claim-note" name="note" maxLength={500} />
                    </div>
                    <Button type="submit" disabled={sending}>
                        შეტყობინების გაგზავნა
                    </Button>
                </form>
            )}

            {claims.length > 0 && (
                <ul className="space-y-2">
                    {claims.map((claim) => (
                        <PaymentClaimItem
                            key={claim.id}
                            claim={claim}
                            money={money}
                            canReview={canReview}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}
