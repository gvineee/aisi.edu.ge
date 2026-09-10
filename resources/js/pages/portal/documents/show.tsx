import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import * as DocumentVersionController from '@/actions/App/Http/Controllers/Portal/DocumentVersionController';
import * as DocumentApprovalController from '@/actions/App/Http/Controllers/Portal/DocumentApprovalController';

type VersionEntry = {
    id: number;
    ordinal: number;
    originalFilename: string;
    mime: string;
    size: number;
    authorName: string;
    createdAt: string | null;
    isPublished: boolean;
    isLatest: boolean;
    downloadUrl: string;
};

type DecisionEntry = {
    reviewerName: string;
    decision: string;
    comment: string | null;
    actedAt: string;
};

type ApprovalHistoryEntry = {
    id: number;
    state: string;
    decisions: DecisionEntry[];
};

type Props = {
    document: {
        id: number;
        title: string;
        type: string;
        status: string;
        workspaceTitle: string;
        ownerName: string;
        updatedAt: string | null;
    };
    versions: VersionEntry[];
    approvalHistory: ApprovalHistoryEntry[];
    canUpload: boolean;
    canSubmit: boolean;
    canDecide: boolean;
};

const statusLabels: Record<string, string> = {
    draft: 'მონახაზი',
    in_review: 'განხილვაში',
    changes_requested: 'დასაზუსტებელი',
    approved: 'დამტკიცებული',
};

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function DocumentShow({
    document,
    versions,
    approvalHistory,
    canUpload,
    canSubmit,
    canDecide,
}: Props) {
    const [uploading, setUploading] = useState(false);
    const [returnComment, setReturnComment] = useState('');
    const [deciding, setDeciding] = useState(false);

    const uploadVersion = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setUploading(true);
        router.post(DocumentVersionController.store.url(document.id), form, {
            forceFormData: true,
            onSuccess: () => toast.success('ახალი ვერსია ატვირთულია.'),
            onError: () => toast.error('ატვირთვა ვერ მოხერხდა.'),
            onFinish: () => {
                setUploading(false);
                event.currentTarget.reset();
            },
        });
    };

    const submitForReview = () => {
        router.post(
            DocumentApprovalController.submit.url(document.id),
            {},
            {
                onSuccess: () => toast.success('გაგზავნილია განსახილველად.'),
            },
        );
    };

    const decide = (
        approvalRequestId: number,
        decision: 'approved' | 'returned',
    ) => {
        if (decision === 'returned' && returnComment.trim() === '') {
            toast.error('დაბრუნებისას განმარტება სავალდებულოა.');
            return;
        }

        setDeciding(true);
        router.post(
            DocumentApprovalController.decide.url(approvalRequestId),
            {
                decision,
                comment: decision === 'returned' ? returnComment : undefined,
            },
            {
                onSuccess: () =>
                    toast.success(
                        decision === 'approved'
                            ? 'დამტკიცებულია.'
                            : 'დაბრუნებულია შესასწორებლად.',
                    ),
                onError: () =>
                    toast.error(
                        'მოქმედება ვერ შესრულდა — შესაძლოა უკვე გადაწყვეტილია.',
                    ),
                onFinish: () => setDeciding(false),
            },
        );
    };

    const pendingApprovalRequest = approvalHistory.find(
        (entry) => entry.state === 'pending',
    );

    return (
        <PortalLayout>
            <Head title={document.title} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl">{document.title}</h1>
                    <p className="text-sm text-slate-500">
                        {document.workspaceTitle} · {document.ownerName}
                    </p>
                </div>
                <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                    {statusLabels[document.status] ?? document.status}
                </span>
            </div>

            {canSubmit && (
                <div className="mb-6 rounded-xl border border-slate-200 bg-white p-4">
                    <Button onClick={submitForReview}>
                        განსახილველად გაგზავნა
                    </Button>
                </div>
            )}

            {canDecide && pendingApprovalRequest && (
                <div className="mb-6 space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm font-medium">
                        დირექტორის გადაწყვეტილება
                    </p>
                    <textarea
                        value={returnComment}
                        onChange={(event) =>
                            setReturnComment(event.target.value)
                        }
                        placeholder="დაბრუნების მიზეზი (სავალდებულოა დაბრუნებისას)"
                        className="h-9 min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                    />
                    <div className="flex gap-2">
                        <Button
                            disabled={deciding}
                            onClick={() =>
                                decide(pendingApprovalRequest.id, 'approved')
                            }
                        >
                            დამტკიცება
                        </Button>
                        <Button
                            variant="outline"
                            disabled={deciding}
                            onClick={() =>
                                decide(pendingApprovalRequest.id, 'returned')
                            }
                        >
                            დაბრუნება შესასწორებლად
                        </Button>
                    </div>
                </div>
            )}

            {canUpload && (
                <form
                    onSubmit={uploadVersion}
                    encType="multipart/form-data"
                    className="mb-8 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4"
                >
                    <div className="flex-1">
                        <label className="mb-1 block text-sm font-medium">
                            ახალი ვერსია
                        </label>
                        <input
                            type="file"
                            name="file"
                            required
                            className="block w-full text-sm"
                        />
                    </div>
                    <Button type="submit" disabled={uploading}>
                        ატვირთვა
                    </Button>
                </form>
            )}

            <h2 className="mb-3 text-lg font-medium">ვერსიები</h2>
            <ul className="mb-8 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                {versions.map((version) => (
                    <li
                        key={version.id}
                        className="flex items-center justify-between p-4"
                    >
                        <div>
                            <p className="font-medium">
                                v{version.ordinal} — {version.originalFilename}
                                {version.isPublished && (
                                    <span className="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">
                                        გამოქვეყნებული
                                    </span>
                                )}
                                {version.isLatest && !version.isPublished && (
                                    <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                        უახლესი
                                    </span>
                                )}
                            </p>
                            <p className="text-sm text-slate-500">
                                {version.authorName} ·{' '}
                                {formatSize(version.size)}
                            </p>
                        </div>
                        <a
                            href={version.downloadUrl}
                            className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium"
                        >
                            ჩამოტვირთვა
                        </a>
                    </li>
                ))}
            </ul>

            {approvalHistory.length > 0 && (
                <>
                    <h2 className="mb-3 text-lg font-medium">
                        განხილვის ისტორია
                    </h2>
                    <ul className="space-y-3">
                        {approvalHistory.map((entry) => (
                            <li
                                key={entry.id}
                                className="rounded-xl border border-slate-200 bg-white p-4"
                            >
                                <p className="mb-2 text-sm font-medium">
                                    სტატუსი:{' '}
                                    {entry.state === 'pending'
                                        ? 'განხილვაშია'
                                        : entry.state}
                                </p>
                                {entry.decisions.map((decision, index) => (
                                    <div
                                        key={index}
                                        className="text-sm text-slate-600"
                                    >
                                        {decision.reviewerName} —{' '}
                                        {decision.decision === 'approved'
                                            ? 'დამტკიცდა'
                                            : 'დაბრუნდა'}
                                        {decision.comment && (
                                            <p className="mt-1 italic">
                                                „{decision.comment}“
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </PortalLayout>
    );
}
