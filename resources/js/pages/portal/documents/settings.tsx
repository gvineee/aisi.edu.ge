import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FolderPlus, Lock, Tags } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AccessGrantsPanel, {
    type AccessGrant,
} from '@/components/documents/access-grants-panel';
import {
    classificationLabels,
    formText,
    selectClass,
} from '@/components/documents/labels';
import { firstError } from '@/lib/assessment';

type WorkspaceRow = {
    id: number;
    title: string;
    description: string | null;
    classification: string;
    visibility: string;
    requiresApproval: boolean;
    isConfidential: boolean;
    isArchived: boolean;
    documentsCount: number;
    grants: AccessGrant[];
};

type CategoryRow = {
    id: number;
    name: string;
    sortOrder: number;
    isArchived: boolean;
    documentsCount: number;
};

type Props = {
    canConfigure: boolean;
    workspaces: WorkspaceRow[];
    categories: CategoryRow[];
    classifications: string[];
    staff: { id: number; name: string }[];
    roleOptions: Record<string, string>;
};

const onError = (errors: Record<string, string>) =>
    toast.error(firstError(errors) ?? 'ვერ შესრულდა.');

function WorkspaceForm({
    workspace,
    classifications,
    onDone,
}: {
    workspace?: WorkspaceRow;
    classifications: string[];
    onDone: () => void;
}) {
    const prefix = workspace ? `ws-${workspace.id}` : 'ws-new';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const data = {
            title: formText(form, 'title', ''),
            description: formText(form, 'description', ''),
            classification: formText(form, 'classification', ''),
            visibility: formText(form, 'visibility', 'all_staff'),
            requires_approval: form.get('requires_approval') !== null,
            is_confidential: form.get('is_confidential') !== null,
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('შენახულია.');
                onDone();
            },
            onError,
        };

        if (workspace) {
            router.put(`/documents/workspaces/${workspace.id}`, data, options);
        } else {
            router.post('/documents/workspaces', data, options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label
                        htmlFor={`${prefix}-title`}
                        className="mb-1 block text-sm font-medium"
                    >
                        სახელი
                    </label>
                    <Input
                        id={`${prefix}-title`}
                        name="title"
                        required
                        maxLength={255}
                        defaultValue={workspace?.title}
                        placeholder="მაგ. ხელშეკრულებები"
                    />
                </div>
                <div>
                    <label
                        htmlFor={`${prefix}-class`}
                        className="mb-1 block text-sm font-medium"
                    >
                        ტიპი
                    </label>
                    <select
                        id={`${prefix}-class`}
                        name="classification"
                        defaultValue={workspace?.classification ?? 'internal'}
                        className={selectClass}
                    >
                        {classifications.map((value) => (
                            <option key={value} value={value}>
                                {classificationLabels[value] ?? value}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div>
                <label
                    htmlFor={`${prefix}-description`}
                    className="mb-1 block text-sm font-medium"
                >
                    აღწერა
                </label>
                <textarea
                    id={`${prefix}-description`}
                    name="description"
                    maxLength={1000}
                    defaultValue={workspace?.description ?? ''}
                    className="min-h-16 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <fieldset className="space-y-1 text-sm">
                <legend className="mb-1 font-medium">ვინ ხედავს</legend>
                <label className="flex min-h-11 items-center gap-2">
                    <input
                        type="radio"
                        name="visibility"
                        value="all_staff"
                        defaultChecked={
                            (workspace?.visibility ?? 'all_staff') ===
                            'all_staff'
                        }
                    />
                    ყველა თანამშრომელი (ნახვა და საკუთარი ფაილის ატვირთვა)
                </label>
                <label className="flex min-h-11 items-center gap-2">
                    <input
                        type="radio"
                        name="visibility"
                        value="restricted"
                        defaultChecked={workspace?.visibility === 'restricted'}
                    />
                    შეზღუდული — მხოლოდ დირექცია და ვისაც წვდომას მიანიჭებთ
                </label>
            </fieldset>
            <label className="flex min-h-11 items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="requires_approval"
                    defaultChecked={workspace?.requiresApproval ?? false}
                />
                ფაილს დირექტორის დამტკიცება სჭირდება
            </label>
            <label className="flex min-h-11 items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="is_confidential"
                    defaultChecked={workspace?.isConfidential ?? false}
                />
                კონფიდენციალური — ყოველი ჩამოტვირთვა ჩაიწეროს აუდიტში
            </label>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" className="min-h-11">
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

function CategoryItem({ category }: { category: CategoryRow }) {
    const [name, setName] = useState(category.name);

    const save = (archived?: boolean) =>
        router.put(
            `/documents/categories/${category.id}`,
            {
                name,
                sort_order: category.sortOrder,
                ...(archived === undefined ? {} : { archived }),
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('შენახულია.'),
                onError,
            },
        );

    return (
        <li className="flex flex-wrap items-center gap-2 py-2">
            <label htmlFor={`cat-${category.id}`} className="sr-only">
                კატეგორიის სახელი
            </label>
            <Input
                id={`cat-${category.id}`}
                value={name}
                maxLength={120}
                onChange={(e) => setName(e.target.value)}
                className="h-11 min-w-0 flex-[1_1_12rem]"
            />
            <span className="text-sm text-slate-500">
                {category.documentsCount} ფაილი
                {category.isArchived && ' · არქივი'}
            </span>
            <Button
                size="sm"
                variant="outline"
                className="min-h-11"
                disabled={name.trim() === '' || name === category.name}
                onClick={() => save()}
            >
                შენახვა
            </Button>
            <Button
                size="sm"
                variant="ghost"
                className="min-h-11"
                onClick={() => save(!category.isArchived)}
            >
                {category.isArchived ? 'აღდგენა' : 'დაარქივება'}
            </Button>
        </li>
    );
}

/**
 * Workspaces (folders), their default audience and grants, and document
 * categories. Director/admin configure everything; a workspace manager by
 * grant sees only the workspaces they manage, and only their grants.
 */
export default function DocumentSettings({
    canConfigure,
    workspaces,
    categories,
    classifications,
    staff,
    roleOptions,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [newCategory, setNewCategory] = useState('');

    const addCategory = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.post(
            '/documents/categories',
            { name: newCategory, sort_order: categories.length },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('კატეგორია დაემატა.');
                    setNewCategory('');
                },
                onError,
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="სივრცეები და წვდომა" />
            <Link
                href="/documents"
                className="mb-3 inline-flex min-h-11 items-center text-sm underline-offset-4 hover:underline"
            >
                ← ფაილების ცენტრი
            </Link>
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">სივრცეები და წვდომა</h1>
                {canConfigure && !creating && (
                    <Button
                        className="min-h-11"
                        onClick={() => setCreating(true)}
                    >
                        <FolderPlus size={16} aria-hidden /> ახალი სივრცე
                    </Button>
                )}
            </div>

            {creating && (
                <section className="mb-6 rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
                    <h2 className="mb-3 text-lg">ახალი სივრცე</h2>
                    <WorkspaceForm
                        classifications={classifications}
                        onDone={() => setCreating(false)}
                    />
                </section>
            )}

            {workspaces.length === 0 ? (
                <p className="mb-8 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                    სივრცე ჯერ არ არის. შექმენით, მაგალითად, „ხელშეკრულებები“ ან
                    „შიდა დოკუმენტაცია“.
                </p>
            ) : (
                <ul className="mb-10 space-y-4">
                    {workspaces.map((workspace) => (
                        <li
                            key={workspace.id}
                            className="rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
                        >
                            {editingId === workspace.id ? (
                                <WorkspaceForm
                                    workspace={workspace}
                                    classifications={classifications}
                                    onDone={() => setEditingId(null)}
                                />
                            ) : (
                                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="text-lg break-words">
                                            {workspace.visibility ===
                                                'restricted' && (
                                                <Lock
                                                    size={16}
                                                    className="mr-1 inline"
                                                    aria-label="შეზღუდული"
                                                />
                                            )}
                                            {workspace.title}
                                            {workspace.isArchived && (
                                                <span className="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs">
                                                    არქივში
                                                </span>
                                            )}
                                        </h2>
                                        <p className="text-sm text-slate-500">
                                            {classificationLabels[
                                                workspace.classification
                                            ] ?? workspace.classification}{' '}
                                            · {workspace.documentsCount} ფაილი ·{' '}
                                            {workspace.visibility ===
                                            'restricted'
                                                ? 'შეზღუდული'
                                                : 'ყველა თანამშრომელი'}
                                            {workspace.requiresApproval &&
                                                ' · დამტკიცებით'}
                                            {workspace.isConfidential &&
                                                ' · კონფიდენციალური'}
                                        </p>
                                        {workspace.description && (
                                            <p className="mt-1 text-sm">
                                                {workspace.description}
                                            </p>
                                        )}
                                    </div>
                                    {canConfigure && (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="min-h-11"
                                                onClick={() =>
                                                    setEditingId(workspace.id)
                                                }
                                            >
                                                შეცვლა
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="min-h-11"
                                                onClick={() =>
                                                    router.post(
                                                        `/documents/workspaces/${workspace.id}/archive`,
                                                        {
                                                            archived:
                                                                !workspace.isArchived,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onError,
                                                        },
                                                    )
                                                }
                                            >
                                                {workspace.isArchived
                                                    ? 'აღდგენა'
                                                    : 'დაარქივება'}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}
                            <AccessGrantsPanel
                                resourceType="workspace"
                                resourceId={workspace.id}
                                grants={workspace.grants}
                                staff={staff}
                                roleOptions={roleOptions}
                                title="დამატებითი წვდომა"
                                description="დირექტორსა და ადმინისტრატორს წვდომა ყოველთვის აქვთ."
                            />
                        </li>
                    ))}
                </ul>
            )}

            {canConfigure && (
                <section
                    aria-labelledby="categories-title"
                    className="rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
                >
                    <h2
                        id="categories-title"
                        className="mb-1 flex items-center gap-2 text-lg"
                    >
                        <Tags size={18} aria-hidden /> კატეგორიები
                    </h2>
                    <p className="mb-3 text-sm text-slate-500">
                        მაგ. შრომითი ხელშეკრულება, ბრძანება, ოქმი, ანგარიში.
                        დაარქივებული კატეგორია ახალ ფაილებზე აღარ ჩანს.
                    </p>
                    {categories.length > 0 && (
                        <ul className="mb-4 divide-y divide-slate-100">
                            {categories.map((category) => (
                                <CategoryItem
                                    key={category.id}
                                    category={category}
                                />
                            ))}
                        </ul>
                    )}
                    <form
                        onSubmit={addCategory}
                        className="flex flex-wrap gap-2"
                    >
                        <label htmlFor="new-category" className="sr-only">
                            ახალი კატეგორია
                        </label>
                        <Input
                            id="new-category"
                            value={newCategory}
                            required
                            maxLength={120}
                            onChange={(e) => setNewCategory(e.target.value)}
                            placeholder="ახალი კატეგორია"
                            className="h-11 min-w-0 flex-[1_1_12rem]"
                        />
                        <Button type="submit" className="min-h-11">
                            დამატება
                        </Button>
                    </form>
                </section>
            )}
        </PortalLayout>
    );
}
