import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Check, FileCheck2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';

type Asset = {
    id: number;
    originalFilename: string;
    mime: string;
    size: number;
    downloadUrl: string;
};

type FeedbackEntry = {
    id: number;
    authorName: string;
    body: string;
    createdAt: string | null;
};

type ItemDetail = {
    id: number;
    studentId: number;
    studentName: string;
    title: string;
    description: string | null;
    subjectName: string | null;
    status: string;
    publishedAt: string | null;
    assets: Asset[];
    feedback: FeedbackEntry[];
};

type Props = {
    item: ItemDetail;
    canEdit: boolean;
    canSubmit: boolean;
    canDecide: boolean;
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'მონახაზი',
    submitted: 'განსახილველად გაგზავნილია',
    published: 'გამოქვეყნებულია',
    returned: 'დაბრუნებულია შესასწორებლად',
    archived: 'დაარქივებულია',
};

function formatSize(bytes: number): string {
    return `${(bytes / 1024).toFixed(0)} KB`;
}

export default function PortfolioShow({
    item,
    canEdit,
    canSubmit,
    canDecide,
}: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [feedback, setFeedback] = useState('');
    const [deciding, setDeciding] = useState(false);

    const uploadAsset = (event: FormEvent) => {
        event.preventDefault();
        if (!file) return;

        setUploading(true);
        const data = new FormData();
        data.append('file', file);

        router.post(`/portal/portfolio/${item.id}/assets`, data, {
            forceFormData: true,
            onSuccess: () => {
                setFile(null);
                toast.success('ფაილი დაემატა.');
            },
            onError: () => toast.error('ფაილი ვერ აიტვირთა.'),
            onFinish: () => setUploading(false),
        });
    };

    const submit = () => {
        setSubmitting(true);
        router.post(
            `/portal/portfolio/${item.id}/submit`,
            {},
            {
                onSuccess: () => toast.success('გაგზავნილია განსახილველად.'),
                onError: () => toast.error('ვერ გაიგზავნა.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const decide = (decision: 'publish' | 'return') => {
        if (decision === 'return' && feedback.trim() === '') {
            toast.error('დაბრუნებისას მიზეზის მითითება სავალდებულოა.');

            return;
        }

        setDeciding(true);
        router.post(
            `/portal/portfolio/${item.id}/decide`,
            { decision, feedback },
            {
                onSuccess: () => {
                    setFeedback('');
                    toast.success(
                        decision === 'publish'
                            ? 'გამოქვეყნებულია.'
                            : 'დაბრუნებულია შესასწორებლად.',
                    );
                },
                onError: () => toast.error('ვერ შესრულდა.'),
                onFinish: () => setDeciding(false),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title={item.title} />

            <div className="mb-6">
                {item.subjectName && (
                    <p className="mb-1 text-xs font-semibold text-slate-500">
                        {item.subjectName}
                    </p>
                )}
                <h1 className="text-2xl">{item.title}</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {item.studentName} ·{' '}
                    <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-600">
                        {STATUS_LABELS[item.status] ?? item.status}
                    </span>
                </p>
            </div>

            {item.description && (
                <p className="mb-6 max-w-2xl text-slate-700">
                    {item.description}
                </p>
            )}

            <section className="mb-6 rounded-xl border border-slate-200 bg-white p-6">
                <h2 className="mb-4 text-base font-semibold">ფაილები</h2>

                {item.assets.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        ჯერ არცერთი ფაილი არ არის.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {item.assets.map((asset) => (
                            <li key={asset.id}>
                                <a
                                    href={asset.downloadUrl}
                                    className="flex items-center gap-3 rounded-lg bg-slate-50 p-3 text-sm hover:bg-slate-100"
                                >
                                    <FileCheck2
                                        size={18}
                                        className="text-slate-500"
                                    />
                                    <span className="flex-1">
                                        {asset.originalFilename}
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        {formatSize(asset.size)}
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}

                {canEdit && (
                    <form
                        onSubmit={uploadAsset}
                        className="mt-4 flex flex-wrap items-center gap-3"
                    >
                        <input
                            type="file"
                            accept="application/pdf,image/png,image/jpeg,image/webp"
                            onChange={(event) =>
                                setFile(event.target.files?.[0] ?? null)
                            }
                            className="text-sm"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={!file || uploading}
                        >
                            ფაილის დამატება
                        </Button>
                    </form>
                )}
            </section>

            {item.feedback.length > 0 && (
                <section className="mb-6 rounded-xl border border-slate-200 bg-white p-6">
                    <h2 className="mb-4 text-base font-semibold">
                        მასწავლებლის უკუკავშირი
                    </h2>
                    <ul className="space-y-4">
                        {item.feedback.map((entry) => (
                            <li
                                key={entry.id}
                                className="border-l-2 border-slate-200 pl-4"
                            >
                                <p className="text-sm">{entry.body}</p>
                                <p className="mt-1 text-xs text-slate-500">
                                    {entry.authorName}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {canSubmit && (
                <div className="mb-6 rounded-xl border border-slate-200 bg-white p-4">
                    <Button onClick={submit} disabled={submitting}>
                        განსახილველად გაგზავნა
                    </Button>
                </div>
            )}

            {canDecide && (
                <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm font-medium">
                        დირექტორის/მასწავლებლის გადაწყვეტილება
                    </p>
                    <textarea
                        value={feedback}
                        onChange={(event) => setFeedback(event.target.value)}
                        placeholder="უკუკავშირი (დაბრუნებისას სავალდებულოა)"
                        className="h-9 min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                    />
                    <div className="flex gap-2">
                        <Button
                            disabled={deciding}
                            onClick={() => decide('publish')}
                        >
                            <Check size={16} /> გამოქვეყნება
                        </Button>
                        <Button
                            variant="outline"
                            disabled={deciding}
                            onClick={() => decide('return')}
                        >
                            დაბრუნება
                        </Button>
                    </div>
                </div>
            )}
        </PortalLayout>
    );
}
