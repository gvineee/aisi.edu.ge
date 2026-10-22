import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { UserRoundCheck } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { selectClass } from '@/components/documents/labels';
import { firstError, formatDateTime } from '@/lib/assessment';
import { successUnlessReauth } from '@/lib/reauth';

type Delegation = {
    id: number;
    delegatorName: string;
    delegateName: string;
    startsAt: string;
    endsAt: string;
    reason: string;
    state: 'active' | 'scheduled' | 'ended' | 'revoked';
    canRevoke: boolean;
};

type Props = {
    delegations: Delegation[];
    canDelegateForOthers: boolean;
    staff: { id: number; name: string }[];
    currentUserId: number;
    maxDays: number;
};

const stateLabels: Record<Delegation['state'], string> = {
    active: 'მოქმედია',
    scheduled: 'დაგეგმილია',
    ended: 'დასრულდა',
    revoked: 'გაუქმდა',
};

/** Local "YYYY-MM-DDTHH:mm" for a datetime-local input, Tbilisi time. */
function localInput(date: Date): string {
    const parts = new Intl.DateTimeFormat('sv-SE', {
        timeZone: 'Asia/Tbilisi',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);

    return parts.replace(' ', 'T');
}

/**
 * Review substitutes: while a delegation is active the delegate may decide
 * the delegator's review-chain steps; decisions record both names.
 */
export default function Delegations({
    delegations,
    canDelegateForOthers,
    staff,
    currentUserId,
    maxDays,
}: Props) {
    const now = new Date();
    const [delegatorId, setDelegatorId] = useState(String(currentUserId));
    const [delegateId, setDelegateId] = useState('');
    const [startsAt, setStartsAt] = useState(localInput(now));
    const [endsAt, setEndsAt] = useState(
        localInput(new Date(now.getTime() + 7 * 86400000)),
    );
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSaving(true);
        router.post(
            '/documents/delegations',
            {
                delegator_id: canDelegateForOthers ? Number(delegatorId) : null,
                delegate_id: Number(delegateId),
                starts_at: startsAt,
                ends_at: endsAt,
                reason,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (page.component === 'auth/confirm-password') {
                        return;
                    }

                    successUnlessReauth('შემცვლელი დაინიშნა.')(page);
                    setDelegateId('');
                    setReason('');
                },
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ შეინახა.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const revoke = (id: number) =>
        router.post(
            `/documents/delegations/${id}/revoke`,
            {},
            {
                preserveScroll: true,
                onSuccess: successUnlessReauth('დელეგირება გაუქმდა.'),
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ გაუქმდა.'),
            },
        );

    return (
        <PortalLayout>
            <Head title="განხილვის შემცვლელები" />
            <Link
                href="/documents"
                className="mb-3 inline-flex min-h-11 items-center text-sm underline-offset-4 hover:underline"
            >
                ← ფაილების ცენტრი
            </Link>
            <h1 className="mb-2 text-2xl">განხილვის შემცვლელები</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                შვებულების ან არყოფნისას შემცვლელი თქვენ ნაცვლად განიხილავს
                დოკუმენტებს, სადაც რეცენზენტების ჯაჭვში ხართ. ვადა მაქსიმუმ{' '}
                {maxDays} დღეა; ყოველი გადაწყვეტილება ორივე სახელით ინახება.
            </p>

            <form
                onSubmit={submit}
                className="mb-8 space-y-3 rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
            >
                <h2 className="text-lg">ახალი შემცვლელი</h2>
                <div className="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                    {canDelegateForOthers && (
                        <div>
                            <label
                                htmlFor="delegator"
                                className="mb-1 block text-sm"
                            >
                                ვის ნაცვლად
                            </label>
                            <select
                                id="delegator"
                                value={delegatorId}
                                onChange={(e) => setDelegatorId(e.target.value)}
                                className={selectClass}
                            >
                                {staff.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div>
                        <label
                            htmlFor="delegate"
                            className="mb-1 block text-sm"
                        >
                            შემცვლელი
                        </label>
                        <select
                            id="delegate"
                            required
                            value={delegateId}
                            onChange={(e) => setDelegateId(e.target.value)}
                            className={selectClass}
                        >
                            <option value="">აირჩიეთ</option>
                            {staff
                                .filter(
                                    (person) =>
                                        String(person.id) !== delegatorId,
                                )
                                .map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                        </select>
                    </div>
                    <div>
                        <label
                            htmlFor="starts-at"
                            className="mb-1 block text-sm"
                        >
                            დაწყება
                        </label>
                        <Input
                            id="starts-at"
                            type="datetime-local"
                            required
                            value={startsAt}
                            onChange={(e) => setStartsAt(e.target.value)}
                            className="h-11"
                        />
                    </div>
                    <div>
                        <label htmlFor="ends-at" className="mb-1 block text-sm">
                            დასრულება
                        </label>
                        <Input
                            id="ends-at"
                            type="datetime-local"
                            required
                            value={endsAt}
                            onChange={(e) => setEndsAt(e.target.value)}
                            className="h-11"
                        />
                    </div>
                </div>
                <div>
                    <label htmlFor="reason" className="mb-1 block text-sm">
                        მიზეზი
                    </label>
                    <Input
                        id="reason"
                        required
                        maxLength={500}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="მაგ. შვებულება"
                        className="h-11"
                    />
                </div>
                <Button type="submit" disabled={saving} className="min-h-11">
                    დანიშვნა
                </Button>
            </form>

            <h2 className="mb-3 text-lg">მიმდინარე და ბოლო 30 დღის</h2>
            {delegations.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <UserRoundCheck className="mx-auto mb-3 h-8 w-8 text-slate-400" />
                    <p className="text-sm text-slate-500">
                        შემცვლელი არ არის დანიშნული.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {delegations.map((delegation) => (
                        <li
                            key={delegation.id}
                            className="flex flex-wrap items-start justify-between gap-3 p-4"
                        >
                            <div className="min-w-0 text-sm">
                                <p className="font-medium break-words">
                                    {delegation.delegateName} —{' '}
                                    {delegation.delegatorName}-ის ნაცვლად
                                </p>
                                <p className="text-slate-600">
                                    {formatDateTime(delegation.startsAt)} –{' '}
                                    {formatDateTime(delegation.endsAt)} ·{' '}
                                    {stateLabels[delegation.state]}
                                </p>
                                <p className="break-words text-slate-500">
                                    {delegation.reason}
                                </p>
                            </div>
                            {delegation.canRevoke && (
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => revoke(delegation.id)}
                                >
                                    გაუქმება
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
