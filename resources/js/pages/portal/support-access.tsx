import { Head } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/assessment';
import { postWithToast } from '@/lib/operations';

type Props = {
    requests: {
        id: number;
        operatorName: string | null;
        reason: string;
        minutes: number;
        status: string;
        isActive: boolean;
        expiresAt: string | null;
        createdAt: string | null;
    }[];
};

const STATUS: Record<string, string> = {
    requested: 'მოლოდინში',
    approved: 'დამტკიცებული',
    denied: 'უარყოფილი',
    revoked: 'გაუქმებული',
};

/**
 * The school decides on platform support access: approve for the requested
 * time, deny, or revoke an active session at any moment.
 */
export default function SupportAccess({ requests }: Props) {
    const decide = (
        id: number,
        decision: 'approve' | 'deny' | 'revoke',
        success: string,
    ) => postWithToast(`/portal/support-access/${id}`, { decision }, success);

    return (
        <PortalLayout>
            <Head title="მხარდაჭერის წვდომა" />
            <h1 className="mb-2 text-2xl">მხარდაჭერის წვდომა</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                პლატფორმის მხარდაჭერა სკოლის მონაცემებს მხოლოდ თქვენი
                დამტკიცებით, დროებით და მხოლოდ სანახავად ხედავს. ყველა მოქმედება
                აღირიცხება.
            </p>
            {requests.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <LifeBuoy className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                    <p className="font-medium">მოთხოვნა არ არის.</p>
                </div>
            ) : (
                <ul className="space-y-3">
                    {requests.map((r) => (
                        <li
                            key={r.id}
                            className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                        >
                            <p className="font-medium">
                                {r.operatorName} · {r.minutes} წუთი ·{' '}
                                {STATUS[r.status] ?? r.status}
                                {r.isActive && r.expiresAt
                                    ? ` (${formatDateTime(r.expiresAt)}-მდე)`
                                    : ''}
                            </p>
                            <p className="mt-1 text-sm">{r.reason}</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {r.status === 'requested' && (
                                    <>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                decide(
                                                    r.id,
                                                    'approve',
                                                    'წვდომა დამტკიცდა.',
                                                )
                                            }
                                        >
                                            დამტკიცება
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                decide(
                                                    r.id,
                                                    'deny',
                                                    'უარყოფილია.',
                                                )
                                            }
                                        >
                                            უარყოფა
                                        </Button>
                                    </>
                                )}
                                {r.isActive && (
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        onClick={() =>
                                            decide(
                                                r.id,
                                                'revoke',
                                                'წვდომა გაუქმდა.',
                                            )
                                        }
                                    >
                                        ახლავე გაუქმება
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
