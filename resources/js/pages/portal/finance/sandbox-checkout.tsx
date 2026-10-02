import { Head, router } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/operations';

type Props = {
    intent: {
        id: number;
        status: string;
        amountMinor: number;
        currency: string;
    };
};

/**
 * Stand-in for a bank's payment page. Nothing here charges a card: each
 * button sends a signed sandbox notification through the real webhook
 * handler.
 */
export default function SandboxCheckout({ intent }: Props) {
    const simulate = (outcome: 'success' | 'failure') =>
        router.post(`/portal/finance/sandbox/${intent.id}/simulate`, {
            outcome,
        });

    return (
        <div className="flex min-h-screen items-center justify-center bg-amber-50 p-4">
            <Head title="Sandbox გადახდა" />
            <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-sm ring-2 ring-amber-400">
                <p className="mb-4 flex items-center gap-2 font-semibold text-amber-900">
                    <FlaskConical aria-hidden /> SANDBOX — რეალური გადახდა არ
                    ხდება
                </p>
                <p className="mb-6 text-3xl font-semibold">
                    {formatMoney(intent.amountMinor, intent.currency)}
                </p>
                {intent.status !== 'created' ? (
                    <p className="text-sm">
                        ეს გადახდა უკვე დამუშავებულია ({intent.status}).
                    </p>
                ) : (
                    <div className="flex flex-col gap-2">
                        <Button onClick={() => simulate('success')}>
                            წარმატებული გადახდის სიმულაცია
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => simulate('failure')}
                        >
                            უარყოფილი გადახდის სიმულაცია
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
