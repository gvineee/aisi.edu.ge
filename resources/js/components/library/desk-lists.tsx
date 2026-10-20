import { Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { firstError, formatDateTime } from '@/lib/assessment';
import { formatDate } from '@/lib/library';

export type LoanRow = {
    id: number;
    title: string;
    resourceId: number;
    inventoryNumber: string;
    borrower: string;
    className: string | null;
    isStaff: boolean;
    dueOn: string;
    status: 'active' | 'returned' | 'lost';
    overdue: boolean;
    renewals: number;
    canRenew: boolean;
    returnedAt: string | null;
};

export type ReservationRow = {
    id: number;
    title: string;
    resourceId: number;
    student: string;
    className: string | null;
    status: 'waiting' | 'ready';
    inventoryNumber: string | null;
    readyUntil: string | null;
    createdAt: string | null;
};

export const deskOptions = (success: string, onDone?: () => void) => ({
    preserveScroll: true,
    onSuccess: () => {
        toast.success(success);
        onDone?.();
    },
    onError: (errors: Record<string, string>) =>
        toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
});

export function LoanList({
    loans,
    showReturned = false,
}: {
    loans: LoanRow[];
    showReturned?: boolean;
}) {
    if (loans.length === 0) {
        return (
            <p className="rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
                სესხი არ არის.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
            {loans.map((loan) => (
                <li
                    key={loan.id}
                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                >
                    <span>
                        <Link
                            href={`/portal/library-desk/resources/${loan.resourceId}`}
                            className="inline-flex min-h-11 items-center font-medium underline-offset-4 hover:underline"
                        >
                            {loan.title}
                        </Link>{' '}
                        · № {loan.inventoryNumber}
                        <span className="block text-slate-600">
                            {loan.borrower}
                            {loan.className && ` · ${loan.className}`}
                            {loan.isStaff && ' · თანამშრომელი'} ·{' '}
                            {loan.status === 'active' ? (
                                <span
                                    className={
                                        loan.overdue
                                            ? 'font-medium text-red-700'
                                            : ''
                                    }
                                >
                                    ვადა {formatDate(loan.dueOn)}
                                    {loan.overdue && ' (გადაცილებული)'}
                                    {loan.renewals > 0 &&
                                        ` · გაგრძელდა ${loan.renewals}-ჯერ`}
                                </span>
                            ) : loan.status === 'lost' ? (
                                'დაკარგული'
                            ) : (
                                `დაბრუნდა ${loan.returnedAt ? formatDateTime(loan.returnedAt) : ''}`
                            )}
                        </span>
                    </span>
                    {loan.status === 'active' && !showReturned && (
                        <span className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/portal/library-desk/loans/${loan.id}/return`,
                                        {},
                                        deskOptions('დაბრუნდა.'),
                                    )
                                }
                            >
                                დაბრუნება
                            </Button>
                            {loan.canRenew && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/portal/library-desk/loans/${loan.id}/renew`,
                                            {},
                                            deskOptions('ვადა გაგრძელდა.'),
                                        )
                                    }
                                >
                                    გაგრძელება
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/portal/library-desk/loans/${loan.id}/lost`,
                                        {},
                                        deskOptions('დაკარგულად მოინიშნა.'),
                                    )
                                }
                            >
                                დაკარგულია
                            </Button>
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

export function ReservationList({
    reservations,
}: {
    reservations: ReservationRow[];
}) {
    if (reservations.length === 0) {
        return (
            <p className="rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
                ჯავშანი არ არის.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
            {reservations.map((r) => (
                <li
                    key={r.id}
                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                >
                    <span>
                        <Link
                            href={`/portal/library-desk/resources/${r.resourceId}`}
                            className="inline-flex min-h-11 items-center font-medium underline-offset-4 hover:underline"
                        >
                            {r.title}
                        </Link>
                        <span className="block text-slate-600">
                            {r.student}
                            {r.className && ` · ${r.className}`} ·{' '}
                            {r.status === 'ready'
                                ? `გადადებულია № ${r.inventoryNumber ?? '—'}${r.readyUntil ? `, ${formatDateTime(r.readyUntil)}-მდე` : ''}`
                                : 'რიგში'}
                        </span>
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/portal/library-desk/reservations/${r.id}/cancel`,
                                {},
                                deskOptions('ჯავშანი გაუქმდა.'),
                            )
                        }
                    >
                        გაუქმება
                    </Button>
                </li>
            ))}
        </ul>
    );
}
