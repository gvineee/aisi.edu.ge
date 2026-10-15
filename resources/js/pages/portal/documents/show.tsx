import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Archive, Lock, Pencil } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AccessGrantsPanel, {
    type AccessGrant,
} from '@/components/documents/access-grants-panel';
import {
    documentStatusLabels,
    formText,
    selectClass,
} from '@/components/documents/labels';
import { firstError, formatDay } from '@/lib/assessment';

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
    scanState: string;
    downloadUrl: string | null;
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
        status: string;
        visibility: string;
        workspaceId: number;
        workspaceTitle: string;
        workspaceRequiresApproval: boolean;
        workspaceIsConfidential: boolean;
        categoryId: number | null;
        categoryName: string | null;
        ownerName: string;
        description: string | null;
        documentDate: string | null;
        expiresOn: string | null;
        expiryState: 'expired' | 'soon' | null;
        tags: string[];
        isArchived: boolean;
        createdAt: string | null;
        updatedAt: string | null;
    };
    versions: VersionEntry[];
    approvalHistory: ApprovalHistoryEntry[];
    canUpload: boolean;
    canEditDetails: boolean;
    canManage: boolean;
    canSubmit: boolean;
    canDecide: boolean;
    categories: { id: number; name: string }[];
    grants: AccessGrant[];
    staff: { id: number; name: string }[];
    roleOptions: Record<string, string>;
};

const scanLabels: Record<string, string> = {
    pending: 'მოწმდება…',
    quarantined: 'დაბლოკილია (საეჭვო ფაილი)',
};

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function DetailsForm({
    document,
    categories,
    canManage,
    onDone,
}: {
    document: Props['document'];
    categories: Props['categories'];
    canManage: boolean;
    onDone: () => void;
}) {
    const [saving, setSaving] = useState(false);

    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSaving(true);
        router.put(
            `/documents/${document.id}`,
            {
                title: formText(form, 'title', ''),
                description: formText(form, 'description', ''),
                category_id: formText(form, 'category_id', ''),
                document_date: formText(form, 'document_date', ''),
                expires_on: formText(form, 'expires_on', ''),
                tags: formText(form, 'tags', ''),
                ...(canManage
                    ? {
                          visibility: form.get('restricted')
                              ? 'restricted'
                              : 'workspace',
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('შენახულია.');
                    onDone();
                },
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ შეინახა.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <form
            onSubmit={save}
            className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
        >
            <div>
                <label
                    htmlFor="d-title"
                    className="mb-1 block text-sm font-medium"
                >
                    სათაური
                </label>
                <Input
                    id="d-title"
                    name="title"
                    required
                    maxLength={255}
                    defaultValue={document.title}
                />
            </div>
            <div>
                <label
                    htmlFor="d-description"
                    className="mb-1 block text-sm font-medium"
                >
                    აღწერა
                </label>
                <textarea
                    id="d-description"
                    name="description"
                    maxLength={2000}
                    defaultValue={document.description ?? ''}
                    className="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        htmlFor="d-category"
                        className="mb-1 block text-sm font-medium"
                    >
                        კატეგორია
                    </label>
                    <select
                        id="d-category"
                        name="category_id"
                        defaultValue={document.categoryId ?? ''}
                        className={selectClass}
                    >
                        <option value="">— არ არის —</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label
                        htmlFor="d-tags"
                        className="mb-1 block text-sm font-medium"
                    >
                        ტეგები (მძიმით)
                    </label>
                    <Input
                        id="d-tags"
                        name="tags"
                        maxLength={500}
                        defaultValue={document.tags.join(', ')}
                    />
                </div>
                <div>
                    <label
                        htmlFor="d-date"
                        className="mb-1 block text-sm font-medium"
                    >
                        დოკუმენტის თარიღი
                    </label>
                    <Input
                        id="d-date"
                        name="document_date"
                        type="date"
                        defaultValue={document.documentDate ?? ''}
                    />
                </div>
                <div>
                    <label
                        htmlFor="d-expires"
                        className="mb-1 block text-sm font-medium"
                    >
                        მოქმედების ვადა
                    </label>
                    <Input
                        id="d-expires"
                        name="expires_on"
                        type="date"
                        defaultValue={document.expiresOn ?? ''}
                    />
                </div>
            </div>
            {canManage && (
                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="restricted"
                        defaultChecked={document.visibility === 'restricted'}
                    />
                    შეზღუდული: მხოლოდ ავტორი, დირექცია, სივრცის მმართველები და
                    ქვემოთ მითითებული პირები/როლები
                </label>
            )}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={saving} className="min-h-11">
                    შენახვა
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={onDone}
                >
                    გაუქმება
                </Button>
            </div>
        </form>
    );
}

export default function DocumentShow({
    document,
    versions,
    approvalHistory,
    canUpload,
    canEditDetails,
    canManage,
    canSubmit,
    canDecide,
    categories,
    grants,
    staff,
    roleOptions,
}: Props) {
    const [uploading, setUploading] = useState(false);
    const [editing, setEditing] = useState(false);
    const [returnComment, setReturnComment] = useState('');
    const [deciding, setDeciding] = useState(false);

    const uploadVersion = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const formElement = event.currentTarget;
        const form = new FormData(formElement);
        setUploading(true);
        router.post(`/documents/${document.id}/versions`, form, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                toast.success('ახალი ვერსია აიტვირთა.');
                formElement.reset();
            },
            onError: (errors) =>
                toast.error(firstError(errors) ?? 'ატვირთვა ვერ მოხერხდა.'),
            onFinish: () => setUploading(false),
        });
    };

    const submitForReview = () => {
        router.post(
            `/documents/${document.id}/submit`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('გაიგზავნა განსახილველად.'),
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ გაიგზავნა.'),
            },
        );
    };

    const setArchived = (archived: boolean) => {
        router.post(
            `/documents/${document.id}/archive`,
            { archived },
            {
                preserveScroll: true,
                onError: () => toast.error('ვერ შესრულდა.'),
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
            `/approval-requests/${approvalRequestId}/decide`,
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

            <Link
                href="/documents"
                className="mb-3 inline-flex min-h-11 items-center text-sm underline-offset-4 hover:underline"
            >
                ← ფაილების ცენტრი
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-2xl break-words">
                        {document.visibility === 'restricted' && (
                            <Lock
                                size={18}
                                className="mr-1 inline"
                                aria-label="შეზღუდული"
                            />
                        )}
                        {document.title}
                    </h1>
                    <p className="text-sm break-words text-slate-500">
                        {document.workspaceTitle}
                        {document.categoryName &&
                            ` · ${document.categoryName}`}{' '}
                        · {document.ownerName}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {document.isArchived && (
                        <span className="rounded-full bg-slate-200 px-3 py-1 text-xs font-medium">
                            არქივში
                        </span>
                    )}
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                        {documentStatusLabels[document.status] ??
                            document.status}
                    </span>
                </div>
            </div>

            {editing ? (
                <DetailsForm
                    document={document}
                    categories={categories}
                    canManage={canManage}
                    onDone={() => setEditing(false)}
                />
            ) : (
                <section className="mb-6 rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-slate-500">
                                დოკუმენტის თარიღი
                            </dt>
                            <dd>
                                {document.documentDate
                                    ? formatDay(document.documentDate)
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">მოქმედების ვადა</dt>
                            <dd
                                className={
                                    document.expiryState === 'expired'
                                        ? 'font-medium text-red-700'
                                        : document.expiryState === 'soon'
                                          ? 'font-medium text-amber-800'
                                          : undefined
                                }
                            >
                                {document.expiresOn
                                    ? `${formatDay(document.expiresOn)}${document.expiryState === 'expired' ? ' (გასულია)' : document.expiryState === 'soon' ? ' (მალე იწურება)' : ''}`
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">ტეგები</dt>
                            <dd>
                                {document.tags.length > 0
                                    ? document.tags
                                          .map((tag) => `#${tag}`)
                                          .join(' ')
                                    : '—'}
                            </dd>
                        </div>
                    </dl>
                    {document.description && (
                        <p className="mt-4 text-sm whitespace-pre-line">
                            {document.description}
                        </p>
                    )}
                    {document.workspaceIsConfidential && (
                        <p className="mt-4 text-xs text-slate-500">
                            კონფიდენციალური სივრცე: ყოველი ჩამოტვირთვა
                            აღირიცხება.
                        </p>
                    )}
                    {(canEditDetails || canManage) && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {canEditDetails && (
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => setEditing(true)}
                                >
                                    <Pencil size={16} aria-hidden /> მონაცემების
                                    შეცვლა
                                </Button>
                            )}
                            {canManage && (
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() =>
                                        setArchived(!document.isArchived)
                                    }
                                >
                                    <Archive size={16} aria-hidden />{' '}
                                    {document.isArchived
                                        ? 'არქივიდან დაბრუნება'
                                        : 'დაარქივება'}
                                </Button>
                            )}
                        </div>
                    )}
                </section>
            )}

            {canSubmit && (
                <div className="mb-6 rounded-xl border border-slate-200 bg-white p-4">
                    <Button onClick={submitForReview} className="min-h-11">
                        განსახილველად გაგზავნა
                    </Button>
                </div>
            )}

            {canDecide && pendingApprovalRequest && (
                <div className="mb-6 space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm font-medium">გადაწყვეტილება</p>
                    <label htmlFor="return-comment" className="sr-only">
                        დაბრუნების მიზეზი
                    </label>
                    <textarea
                        id="return-comment"
                        value={returnComment}
                        onChange={(event) =>
                            setReturnComment(event.target.value)
                        }
                        placeholder="დაბრუნების მიზეზი (სავალდებულოა დაბრუნებისას)"
                        className="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={deciding}
                            className="min-h-11"
                            onClick={() =>
                                decide(pendingApprovalRequest.id, 'approved')
                            }
                        >
                            დამტკიცება
                        </Button>
                        <Button
                            variant="outline"
                            disabled={deciding}
                            className="min-h-11"
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
                    <div className="min-w-0 flex-[1_1_14rem]">
                        <label
                            htmlFor="new-version"
                            className="mb-1 block text-sm font-medium"
                        >
                            ახალი ვერსია
                            {!document.workspaceRequiresApproval &&
                                ' (მაშინვე გახდება მოქმედი)'}
                        </label>
                        <input
                            id="new-version"
                            type="file"
                            name="file"
                            required
                            className="block w-full text-sm"
                            accept=".pdf,.docx,.xlsx,.pptx,image/png,image/jpeg,image/webp"
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={uploading}
                        className="min-h-11"
                    >
                        ატვირთვა
                    </Button>
                </form>
            )}

            <h2 className="mb-3 text-lg font-medium">ვერსიები</h2>
            {versions.length === 0 ? (
                <p className="mb-8 text-sm text-slate-500">
                    მოქმედი ვერსია ჯერ არ არის.
                </p>
            ) : (
                <ul className="mb-8 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {versions.map((version) => (
                        <li
                            key={version.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-4"
                        >
                            <div className="min-w-0">
                                <p className="font-medium break-words">
                                    v{version.ordinal} —{' '}
                                    {version.originalFilename}
                                    {version.isPublished && (
                                        <span className="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">
                                            მოქმედი
                                        </span>
                                    )}
                                    {version.isLatest &&
                                        !version.isPublished && (
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
                            {version.downloadUrl ? (
                                <a
                                    href={version.downloadUrl}
                                    className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 px-3 text-sm font-medium"
                                >
                                    ჩამოტვირთვა
                                </a>
                            ) : (
                                <span className="text-sm text-slate-500">
                                    {scanLabels[version.scanState] ??
                                        'მიუწვდომელია'}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="mb-8 rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
                    <AccessGrantsPanel
                        resourceType="document"
                        resourceId={document.id}
                        grants={grants}
                        staff={staff}
                        roleOptions={roleOptions}
                        title="ამ დოკუმენტის დამატებითი წვდომა"
                        description="სივრცის წვდომის გარდა — მაგ. კონკრეტული ხელშეკრულება ბუღალტერიას."
                    />
                </div>
            )}

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
                                        : entry.state === 'approved'
                                          ? 'დამტკიცდა'
                                          : entry.state === 'returned'
                                            ? 'დაბრუნდა'
                                            : 'ჩანაცვლდა'}
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
