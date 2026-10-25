import { router } from '@inertiajs/react';
import { FolderTree } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type CategoryOption = { id: number; name: string; isPublic: boolean };

export type Placement = { categoryId: number; section: string | null } | null;

/**
 * Site category of a page or post. Saving changes only where the item is
 * listed — never its text, date or status.
 */
export default function CategoryPicker({
    type,
    id,
    categories,
    placement,
}: {
    type: 'pages' | 'posts';
    id: number;
    categories: CategoryOption[];
    placement: Placement;
}) {
    const [categoryId, setCategoryId] = useState<string>(
        placement ? String(placement.categoryId) : '',
    );
    const [section, setSection] = useState(placement?.section ?? '');
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.put(
            `/portal/cms/placements/${type}/${id}`,
            {
                content_category_id:
                    categoryId === '' ? null : Number(categoryId),
                section,
            },
            {
                preserveScroll: true,
                onError: () => toast.error('ვერ შეინახა.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <FolderTree size={16} /> საიტის განყოფილება
            </h2>
            <label
                htmlFor="placement-category"
                className="mb-1 block text-xs font-medium text-slate-600"
            >
                კატეგორია
            </label>
            <select
                id="placement-category"
                value={categoryId}
                onChange={(event) => setCategoryId(event.target.value)}
                className="mb-3 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
            >
                <option value="">— არ არის —</option>
                {categories.map((category) => (
                    <option key={category.id} value={category.id}>
                        {category.name}
                        {category.isPublic ? '' : ' (არ ჩანს საიტზე)'}
                    </option>
                ))}
            </select>
            <label
                htmlFor="placement-section"
                className="mb-1 block text-xs font-medium text-slate-600"
            >
                ქვეგანყოფილება (არასავალდებულო)
            </label>
            <input
                id="placement-section"
                value={section}
                maxLength={64}
                onChange={(event) => setSection(event.target.value)}
                className="mb-3 h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
            />
            <button
                type="button"
                onClick={save}
                disabled={saving}
                className="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-4 text-sm font-medium text-white disabled:opacity-60"
            >
                შენახვა
            </button>
        </section>
    );
}
