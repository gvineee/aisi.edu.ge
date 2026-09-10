import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type QueueItem = {
    id: number;
    title: string;
    subjectName: string | null;
    studentName: string;
    updatedAt: string | null;
};

type Props = {
    items: QueueItem[];
};

export default function PortfolioReviewQueue({ items }: Props) {
    return (
        <PortalLayout>
            <Head title="პორტფოლიოს განხილვა" />

            <h1 className="mb-6 text-2xl">პორტფოლიოს განხილვა</h1>

            {items.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <CheckCircle2 className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        ამჟამად განსახილველი ნამუშევარი არ არის.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {items.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={`/portal/portfolio/${item.id}`}
                                className="flex items-center justify-between gap-3 p-4 text-sm hover:bg-slate-50"
                            >
                                <div>
                                    <p className="font-medium">{item.title}</p>
                                    <p className="text-slate-500">
                                        {item.studentName}
                                        {item.subjectName &&
                                            ` · ${item.subjectName}`}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
