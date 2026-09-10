import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FileText, Search, Upload } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as DocumentController from '@/actions/App/Http/Controllers/Portal/DocumentController';

type Workspace = { id: number; title: string; classification: string };

type DocumentSummary = {
    id: number;
    title: string;
    type: string;
    status: string;
    workspaceTitle: string;
    ownerName: string;
    updatedAt: string | null;
};

type Props = {
    workspaces: Workspace[];
    documents: DocumentSummary[];
    filters: { search: string; workspace_id: string; status: string };
    isDirectorOrAdmin: boolean;
};

const statusLabels: Record<string, string> = {
    draft: 'მონახაზი',
    in_review: 'განხილვაში',
    changes_requested: 'დასაზუსტებელი',
    approved: 'დამტკიცებული',
};

export default function DocumentCenterIndex({
    workspaces,
    documents,
    filters,
    isDirectorOrAdmin,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [showCreate, setShowCreate] = useState(false);
    const [creating, setCreating] = useState(false);

    const applyFilters = (next: Partial<Props['filters']>) => {
        router.get(
            DocumentController.index.url(),
            {
                search,
                workspace_id: filters.workspace_id,
                status: filters.status,
                ...next,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitCreate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setCreating(true);
        router.post(DocumentController.store.url(), form, {
            forceFormData: true,
            onSuccess: () => toast.success('მონახაზი შეიქმნა.'),
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setCreating(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="ფაილების ცენტრი" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ფაილების ცენტრი</h1>
                <div className="flex gap-2">
                    <Link
                        href="/documents/my-work"
                        className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium"
                    >
                        ჩემი სამუშაო
                    </Link>
                    {isDirectorOrAdmin && (
                        <Link
                            href="/documents/director-worklist"
                            className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium"
                        >
                            სამუშაო სია
                        </Link>
                    )}
                    <Button onClick={() => setShowCreate((v) => !v)}>
                        <Upload size={16} /> ახალი მონახაზი
                    </Button>
                </div>
            </div>

            {showCreate && (
                <form
                    onSubmit={submitCreate}
                    encType="multipart/form-data"
                    className="mb-8 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            სამუშაო სივრცე
                        </label>
                        <select
                            name="workspace_id"
                            required
                            className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                        >
                            {workspaces.map((workspace) => (
                                <option key={workspace.id} value={workspace.id}>
                                    {workspace.title}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            ტიპი
                        </label>
                        <Input
                            name="type"
                            required
                            placeholder="lesson_plan, policy, minutes..."
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            სათაური
                        </label>
                        <Input name="title" required />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            ფაილი (PDF/DOCX/XLSX/PPTX ან სურათი, მაქს. 20MB)
                        </label>
                        <input
                            type="file"
                            name="file"
                            required
                            className="block w-full text-sm"
                            accept=".pdf,.docx,.xlsx,.pptx,image/png,image/jpeg,image/webp"
                        />
                    </div>
                    <Button type="submit" disabled={creating}>
                        შექმნა
                    </Button>
                </form>
            )}

            <div className="mb-6 flex flex-wrap gap-3">
                <div className="relative min-w-[200px] flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) =>
                            event.key === 'Enter' && applyFilters({ search })
                        }
                        placeholder="ძიება სათაურით..."
                        className="pl-9"
                    />
                </div>
                <select
                    value={filters.workspace_id}
                    onChange={(event) =>
                        applyFilters({ workspace_id: event.target.value })
                    }
                    className="h-9 rounded-md border border-slate-300 px-3 text-sm"
                >
                    <option value="">ყველა სივრცე</option>
                    {workspaces.map((workspace) => (
                        <option key={workspace.id} value={workspace.id}>
                            {workspace.title}
                        </option>
                    ))}
                </select>
                <select
                    value={filters.status}
                    onChange={(event) =>
                        applyFilters({ status: event.target.value })
                    }
                    className="h-9 rounded-md border border-slate-300 px-3 text-sm"
                >
                    <option value="">ყველა სტატუსი</option>
                    {Object.entries(statusLabels).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>

            {documents.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <FileText className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">დოკუმენტი ვერ მოიძებნა.</p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {documents.map((document) => (
                        <li key={document.id} className="p-4">
                            <Link
                                href={DocumentController.show.url(document.id)}
                                className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {document.title}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {document.workspaceTitle} ·{' '}
                                        {document.ownerName}
                                    </p>
                                </div>
                                <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                    {statusLabels[document.status] ??
                                        document.status}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
