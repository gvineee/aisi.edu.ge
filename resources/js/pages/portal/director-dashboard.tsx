import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';

type PreviewItem = {
    id: number;
    documentId: number;
    documentTitle: string;
    authorName: string;
    submittedAt: string | null;
};

type Props = {
    pendingCount: number;
    preview: PreviewItem[];
    actionItems: ActionItem[];
};

export default function DirectorDashboard({
    pendingCount,
    preview,
    actionItems,
}: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <h1 className="mb-2 text-2xl">დღეს</h1>
            <p className="mb-8 text-sm text-slate-500">
                {new Date().toLocaleDateString('ka-GE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                })}
            </p>

            <section className="rounded-xl bg-[var(--brand-primary)] p-8 text-white">
                <p className="text-xs tracking-wide text-white/70">
                    დასამტკიცებელი დოკუმენტები
                </p>
                <h2 className="mt-2 text-3xl">{pendingCount}</h2>
                <Link
                    href="/documents/director-worklist"
                    className="mt-4 inline-flex min-h-11 items-center gap-2 rounded-lg bg-white/15 px-4 py-2 text-sm font-medium hover:bg-white/25"
                >
                    <ClipboardCheck size={16} /> სამუშაო სიის ნახვა
                </Link>
            </section>

            <div className="mt-6">
                <ActionFeed items={actionItems} />
            </div>

            <section className="mt-6 rounded-xl border border-slate-200 bg-white p-6">
                <h3 className="mb-4 text-base font-semibold">
                    ბოლოდროინდელი მოთხოვნები
                </h3>

                {preview.length === 0 ? (
                    <div className="py-6 text-center">
                        <CheckCircle2 className="mx-auto mb-3 h-8 w-8 text-slate-400" />
                        <p className="text-sm text-slate-500">
                            ამჟამად დასამტკიცებელი დოკუმენტი არ არის.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {preview.map((item) => (
                            <li key={item.id} className="py-3">
                                <Link
                                    href={`/documents/${item.documentId}`}
                                    className="flex items-center justify-between gap-3 text-sm"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {item.documentTitle}
                                        </p>
                                        <p className="text-slate-500">
                                            {item.authorName}
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
