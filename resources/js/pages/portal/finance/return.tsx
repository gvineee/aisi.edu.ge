import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock, XCircle } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import SandboxBanner from '@/components/portal/sandbox-banner';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/operations';

type Props = {
    intent: {
        id: number;
        status: string;
        amountMinor: number;
        currency: string;
        invoiceId: number;
    };
};

/**
 * Return page after checkout. It reports only what the server has
 * confirmed through the provider's signed notification — arriving here is
 * not proof of payment.
 */
export default function FinanceReturn({ intent }: Props) {
    return (
        <PortalLayout>
            <Head title="გადახდის სტატუსი" />
            <h1 className="mb-4 text-2xl">გადახდის სტატუსი</h1>
            <SandboxBanner />
            <div
                role="status"
                className="mb-6 flex items-start gap-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
            >
                {intent.status === 'succeeded' ? (
                    <CheckCircle2
                        className="shrink-0 text-emerald-600"
                        aria-hidden
                    />
                ) : intent.status === 'failed' ? (
                    <XCircle className="shrink-0 text-red-600" aria-hidden />
                ) : (
                    <Clock className="shrink-0 text-slate-500" aria-hidden />
                )}
                <div>
                    <p className="font-semibold">
                        {intent.status === 'succeeded'
                            ? 'გადახდა დადასტურებულია'
                            : intent.status === 'failed'
                              ? 'გადახდა არ შესრულდა'
                              : 'ველოდებით ბანკის დადასტურებას'}
                    </p>
                    <p className="text-sm text-slate-600">
                        {formatMoney(intent.amountMinor, intent.currency)}
                    </p>
                    {intent.status === 'created' && (
                        <p className="mt-1 text-sm text-slate-600">
                            გადახდა ჩაითვლება მხოლოდ ბანკის ოფიციალური
                            შეტყობინების მიღების შემდეგ.
                        </p>
                    )}
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                {intent.status === 'created' && (
                    <Button variant="outline" onClick={() => router.reload()}>
                        სტატუსის განახლება
                    </Button>
                )}
                <Link
                    href={`/portal/finance/invoices/${intent.invoiceId}`}
                    className="inline-flex min-h-11 items-center rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                >
                    ინვოისზე დაბრუნება
                </Link>
            </div>
        </PortalLayout>
    );
}
