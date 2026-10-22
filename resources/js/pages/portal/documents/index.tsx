import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import {
    AlertTriangle,
    FileText,
    Lock,
    Search,
    Settings2,
    Upload,
} from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDay } from '@/lib/assessment';
import {
    documentStatusLabels,
    selectClass,
} from '@/components/documents/labels';

type Workspace = {
    id: number;
    title: string;
    classification: string;
    visibility: string;
    requiresApproval: boolean;
    isArchived: boolean;
    canCreate: boolean;
};

type DocumentSummary = {
    id: number;
    title: string;
    status: string;
    visibility: string;
    workspaceTitle: string;
    categoryName: string | null;
    ownerName: string;
    documentDate: string | null;
    expiresOn: string | null;
    expiryState: 'expired' | 'soon' | null;
    tags: string[];
    isArchived: boolean;
    createdAt: string | null;
    updatedAt: string | null;
};

type Filters = {
    search: string;
    workspace_id: string;
    category_id: string;
    status: string;
    owner_id: string;
    date_from: string;
    date_to: string;
    sort: string;
    expiring: string;
    archived: string;
};

type Props = {
    workspaces: Workspace[];
    categories: { id: number; name: string; isArchived: boolean }[];
    uploaders: { id: number; name: string }[];
    documents: DocumentSummary[];
    pagination: {
        currentPage: number;
        lastPage: number;
        total: number;
        prevUrl: string | null;
        nextUrl: string | null;
    };
    filters: Filters;
    expiringCount: number;
    isDirectorOrAdmin: boolean;
    myReviewCount: number;
};

const sortLabels: Record<string, string> = {
    updated: 'ბოლოს შეცვლილი',
    document_date_desc: 'დოკუმენტის თარიღი (ახალი → ძველი)',
    document_date_asc: 'დოკუმენტის თარიღი (ძველი → ახალი)',
    created_desc: 'ატვირთვის თარიღი',
    title: 'სათაური (ა–ჰ)',
};

function Field({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-sm font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}

/**
 * Internal files: everything the viewer may see (the server applies the
 * workspace/document grants), filterable by workspace, category, document
 * date and uploader. Cards stack on narrow screens.
 */
export default function DocumentCenterIndex({
    workspaces,
    categories,
    uploaders,
    documents,
    pagination,
    filters,
    expiringCount,
    isDirectorOrAdmin,
    myReviewCount,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [showCreate, setShowCreate] = useState(false);
    const [showFilters, setShowFilters] = useState(
        filters.category_id !== '' ||
            filters.owner_id !== '' ||
            filters.date_from !== '' ||
            filters.date_to !== '' ||
            filters.status !== '',
    );
    const [creating, setCreating] = useState(false);
    const creatable = workspaces.filter((w) => w.canCreate && !w.isArchived);
    const [createWorkspaceId, setCreateWorkspaceId] = useState(
        creatable[0]?.id ?? 0,
    );
    const createWorkspace = creatable.find((w) => w.id === createWorkspaceId);
    const activeCategories = categories.filter((c) => !c.isArchived);

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, search, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value !== ''),
        );
        router.get('/documents', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitCreate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setCreating(true);
        router.post('/documents', form, {
            forceFormData: true,
            onSuccess: () => toast.success('ფაილი აიტვირთა.'),
            onError: (errors) =>
                toast.error(firstError(errors) ?? 'შეამოწმეთ ფორმის ველები.'),
            onFinish: () => setCreating(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="ფაილების ცენტრი" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ფაილების ცენტრი</h1>
                <div className="flex flex-wrap gap-2">
                    <Link
                        href="/documents/my-work"
                        className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium"
                    >
                        ჩემი სამუშაო
                    </Link>
                    <Link
                        href="/documents/templates"
                        className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium"
                    >
                        შაბლონები
                    </Link>
                    <Link
                        href="/documents/delegations"
                        className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium"
                    >
                        შემცვლელები
                    </Link>
                    {(isDirectorOrAdmin || myReviewCount > 0) && (
                        <Link
                            href="/documents/director-worklist"
                            className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium"
                        >
                            დასამტკიცებელი
                            {myReviewCount > 0 && ` (${myReviewCount})`}
                        </Link>
                    )}
                    {isDirectorOrAdmin && (
                        <>
                            <Link
                                href="/documents/settings"
                                className="inline-flex min-h-11 items-center gap-1 rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium"
                            >
                                <Settings2 size={16} aria-hidden /> სივრცეები და
                                წვდომა
                            </Link>
                        </>
                    )}
                    {creatable.length > 0 && (
                        <Button
                            onClick={() => setShowCreate((v) => !v)}
                            aria-expanded={showCreate}
                            className="min-h-11"
                        >
                            <Upload size={16} aria-hidden /> ფაილის ატვირთვა
                        </Button>
                    )}
                </div>
            </div>

            {expiringCount > 0 && (
                <div
                    role="status"
                    className="mb-6 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    <span className="flex items-center gap-2">
                        <AlertTriangle size={18} aria-hidden />
                        {expiringCount} დოკუმენტს 30 დღეში ეწურება ვადა.
                    </span>
                    <button
                        type="button"
                        className="min-h-11 font-medium underline"
                        onClick={() =>
                            applyFilters({
                                expiring: '1',
                                sort: 'document_date_asc',
                            })
                        }
                    >
                        ნახვა
                    </button>
                </div>
            )}

            {showCreate && createWorkspace && (
                <form
                    onSubmit={submitCreate}
                    encType="multipart/form-data"
                    className="mb-8 space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="doc-workspace" label="სივრცე">
                            <select
                                id="doc-workspace"
                                name="workspace_id"
                                required
                                value={createWorkspaceId}
                                onChange={(e) =>
                                    setCreateWorkspaceId(Number(e.target.value))
                                }
                                className={selectClass}
                            >
                                {creatable.map((workspace) => (
                                    <option
                                        key={workspace.id}
                                        value={workspace.id}
                                    >
                                        {workspace.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field id="doc-category" label="კატეგორია">
                            <select
                                id="doc-category"
                                name="category_id"
                                defaultValue=""
                                className={selectClass}
                            >
                                <option value="">— არ არის —</option>
                                {activeCategories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    <p className="text-sm text-slate-600">
                        {createWorkspace.requiresApproval
                            ? 'ამ სივრცეში ფაილი ჯერ მონახაზია და დირექტორის დამტკიცების შემდეგ ხდება ხილული.'
                            : 'ამ სივრცეში ატვირთული ფაილი მაშინვე მოქმედია — დამტკიცება არ სჭირდება.'}
                    </p>
                    <Field id="doc-title" label="სათაური">
                        <Input
                            id="doc-title"
                            name="title"
                            required
                            maxLength={255}
                        />
                    </Field>
                    <Field id="doc-description" label="აღწერა (არასავალდებულო)">
                        <textarea
                            id="doc-description"
                            name="description"
                            maxLength={2000}
                            className="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field id="doc-date" label="დოკუმენტის თარიღი">
                            <Input
                                id="doc-date"
                                name="document_date"
                                type="date"
                            />
                        </Field>
                        <Field id="doc-expires" label="მოქმედების ვადა">
                            <Input
                                id="doc-expires"
                                name="expires_on"
                                type="date"
                            />
                        </Field>
                        <Field id="doc-tags" label="ტეგები (მძიმით)">
                            <Input
                                id="doc-tags"
                                name="tags"
                                maxLength={500}
                                placeholder="მაგ. 2026, კადრები"
                            />
                        </Field>
                    </div>
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="visibility"
                            value="restricted"
                        />
                        მხოლოდ მე, დირექცია და სივრცის მმართველები
                    </label>
                    <Field
                        id="doc-file"
                        label="ფაილი (PDF/DOCX/XLSX/PPTX ან სურათი, მაქს. 20MB)"
                    >
                        <input
                            id="doc-file"
                            type="file"
                            name="file"
                            required
                            className="block w-full text-sm"
                            accept=".pdf,.docx,.xlsx,.pptx,image/png,image/jpeg,image/webp"
                        />
                    </Field>
                    <Button
                        type="submit"
                        disabled={creating}
                        className="min-h-11"
                    >
                        ატვირთვა
                    </Button>
                </form>
            )}

            <form
                role="search"
                className="mb-4 flex flex-wrap gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    applyFilters({ search });
                }}
            >
                <div className="relative min-w-0 flex-[1_1_14rem]">
                    <label htmlFor="doc-search" className="sr-only">
                        ძიება
                    </label>
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                        aria-hidden
                    />
                    <Input
                        id="doc-search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="ძიება სათაურით ან აღწერით…"
                        className="h-11 pl-9"
                    />
                </div>
                <label htmlFor="doc-filter-workspace" className="sr-only">
                    სივრცე
                </label>
                <select
                    id="doc-filter-workspace"
                    value={filters.workspace_id}
                    onChange={(event) =>
                        applyFilters({ workspace_id: event.target.value })
                    }
                    className={`${selectClass} w-auto min-w-0 flex-[1_1_10rem]`}
                >
                    <option value="">ყველა სივრცე</option>
                    {workspaces.map((workspace) => (
                        <option key={workspace.id} value={workspace.id}>
                            {workspace.title}
                        </option>
                    ))}
                </select>
                <label htmlFor="doc-sort" className="sr-only">
                    დალაგება
                </label>
                <select
                    id="doc-sort"
                    value={filters.sort}
                    onChange={(event) =>
                        applyFilters({ sort: event.target.value })
                    }
                    className={`${selectClass} w-auto min-w-0 flex-[1_1_10rem]`}
                >
                    {Object.entries(sortLabels).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    aria-expanded={showFilters}
                    aria-controls="doc-more-filters"
                    onClick={() => setShowFilters((v) => !v)}
                >
                    ფილტრები
                </Button>
            </form>

            {showFilters && (
                <div
                    id="doc-more-filters"
                    className="mb-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <Field id="f-category" label="კატეგორია">
                        <select
                            id="f-category"
                            value={filters.category_id}
                            onChange={(e) =>
                                applyFilters({ category_id: e.target.value })
                            }
                            className={selectClass}
                        >
                            <option value="">ყველა</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                    {category.isArchived ? ' (არქივი)' : ''}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field id="f-owner" label="ატვირთა">
                        <select
                            id="f-owner"
                            value={filters.owner_id}
                            onChange={(e) =>
                                applyFilters({ owner_id: e.target.value })
                            }
                            className={selectClass}
                        >
                            <option value="">ყველა</option>
                            {uploaders.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field id="f-status" label="სტატუსი">
                        <select
                            id="f-status"
                            value={filters.status}
                            onChange={(e) =>
                                applyFilters({ status: e.target.value })
                            }
                            className={selectClass}
                        >
                            <option value="">ყველა</option>
                            {Object.entries(documentStatusLabels).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field id="f-from" label="დოკუმენტის თარიღი — დან">
                        <Input
                            id="f-from"
                            type="date"
                            defaultValue={filters.date_from}
                            onBlur={(e) =>
                                e.target.value !== filters.date_from &&
                                applyFilters({ date_from: e.target.value })
                            }
                        />
                    </Field>
                    <Field id="f-to" label="დოკუმენტის თარიღი — მდე">
                        <Input
                            id="f-to"
                            type="date"
                            defaultValue={filters.date_to}
                            onBlur={(e) =>
                                e.target.value !== filters.date_to &&
                                applyFilters({ date_to: e.target.value })
                            }
                        />
                    </Field>
                    <div className="flex flex-col justify-end gap-1">
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={filters.expiring === '1'}
                                onChange={(e) =>
                                    applyFilters({
                                        expiring: e.target.checked ? '1' : '',
                                    })
                                }
                            />
                            ვადა 30 დღეში იწურება ან გასულია
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={filters.archived === '1'}
                                onChange={(e) =>
                                    applyFilters({
                                        archived: e.target.checked ? '1' : '',
                                    })
                                }
                            />
                            არქივი
                        </label>
                    </div>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <button
                            type="button"
                            className="min-h-11 text-sm underline"
                            onClick={() => {
                                setSearch('');
                                router.get(
                                    '/documents',
                                    {},
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            ფილტრების გასუფთავება
                        </button>
                    </div>
                </div>
            )}

            <p className="mb-2 text-sm text-slate-500" aria-live="polite">
                ნაპოვნია {pagination.total}
            </p>

            {documents.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <FileText
                        className="mx-auto mb-4 h-10 w-10 text-slate-400"
                        aria-hidden
                    />
                    <p className="font-medium">დოკუმენტი ვერ მოიძებნა.</p>
                </div>
            ) : (
                <ul className="grid gap-3">
                    {documents.map((document) => (
                        <li key={document.id}>
                            <Link
                                href={`/documents/${document.id}`}
                                className="block rounded-xl border border-slate-200 bg-white p-4 hover:border-slate-300 focus-visible:outline-2"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p className="min-w-0 font-medium break-words">
                                        {document.visibility ===
                                            'restricted' && (
                                            <Lock
                                                size={14}
                                                className="mr-1 inline"
                                                aria-label="შეზღუდული"
                                            />
                                        )}
                                        {document.title}
                                    </p>
                                    <span className="inline-flex shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                        {documentStatusLabels[
                                            document.status
                                        ] ?? document.status}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm break-words text-slate-500">
                                    {document.workspaceTitle}
                                    {document.categoryName &&
                                        ` · ${document.categoryName}`}{' '}
                                    · {document.ownerName}
                                </p>
                                <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-600">
                                    {document.documentDate && (
                                        <span>
                                            თარიღი:{' '}
                                            {formatDay(document.documentDate)}
                                        </span>
                                    )}
                                    {document.expiresOn && (
                                        <span
                                            className={
                                                document.expiryState ===
                                                'expired'
                                                    ? 'font-medium text-red-700'
                                                    : document.expiryState ===
                                                        'soon'
                                                      ? 'font-medium text-amber-800'
                                                      : undefined
                                            }
                                        >
                                            {document.expiryState === 'expired'
                                                ? 'ვადა გავიდა: '
                                                : 'მოქმედებს: '}
                                            {formatDay(document.expiresOn)}
                                        </span>
                                    )}
                                    {document.tags.map((tag) => (
                                        <span
                                            key={tag}
                                            className="rounded bg-slate-100 px-2 text-xs leading-6"
                                        >
                                            #{tag}
                                        </span>
                                    ))}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {pagination.lastPage > 1 && (
                <nav
                    aria-label="გვერდები"
                    className="mt-6 flex items-center justify-between gap-2 text-sm"
                >
                    {pagination.prevUrl ? (
                        <Link
                            href={pagination.prevUrl}
                            preserveScroll
                            className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 bg-white px-4"
                        >
                            ← წინა
                        </Link>
                    ) : (
                        <span />
                    )}
                    <span>
                        {pagination.currentPage} / {pagination.lastPage}
                    </span>
                    {pagination.nextUrl ? (
                        <Link
                            href={pagination.nextUrl}
                            preserveScroll
                            className="inline-flex min-h-11 items-center rounded-lg border border-slate-200 bg-white px-4"
                        >
                            შემდეგი →
                        </Link>
                    ) : (
                        <span />
                    )}
                </nav>
            )}
        </PortalLayout>
    );
}
