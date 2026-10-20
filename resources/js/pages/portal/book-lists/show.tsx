import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { firstError } from '@/lib/assessment';
import {
    ACQUISITION_LABELS,
    REQUIREMENT_LABELS,
    type BookListItemRow,
} from '@/lib/library';

type Props = {
    schoolClass: { id: number; name: string; yearName: string | null };
    items: BookListItemRow[];
    catalog: { id: number; label: string }[];
};

const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

/**
 * One class's book list: add, edit and remove books. Families see the
 * list on „წიგნები“; each change is audited on the server.
 */
export default function BookListShow({ schoolClass, items, catalog }: Props) {
    const [editing, setEditing] = useState<BookListItemRow | null>(null);
    const [formKey, setFormKey] = useState(0);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget));
        const done = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(editing ? 'შენახულია.' : 'წიგნი დაემატა.');
                setEditing(null);
                setFormKey((k) => k + 1);
            },
            onError: (errors: Record<string, string>) =>
                toast.error(firstError(errors) ?? 'ვერ შეინახა.'),
        };

        if (editing) {
            router.put(`/portal/book-list-items/${editing.id}`, data, done);
        } else {
            router.post(
                `/portal/book-lists/${schoolClass.id}/items`,
                data,
                done,
            );
        }
    };

    return (
        <PortalLayout>
            <Head title={`წიგნების სია — ${schoolClass.name}`} />
            <Link
                href="/portal/book-lists"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> წიგნების სიები
            </Link>
            <h1 className="mb-6 text-2xl">
                {schoolClass.name}
                {schoolClass.yearName && (
                    <span className="text-base text-slate-500">
                        {' '}
                        · {schoolClass.yearName}
                    </span>
                )}
            </h1>

            {items.length === 0 ? (
                <p className="mb-6 rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
                    სია ცარიელია — დაამატეთ პირველი წიგნი ქვემოთ.
                </p>
            ) : (
                <ul className="mb-6 divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-wrap items-start justify-between gap-2 p-3"
                        >
                            <span>
                                <span className="block font-medium">
                                    {item.title}
                                    {item.edition && ` (${item.edition})`}
                                </span>
                                <span className="block text-slate-600">
                                    {[
                                        item.author,
                                        item.subject,
                                        item.isbn && `ISBN ${item.isbn}`,
                                        REQUIREMENT_LABELS[item.requirement],
                                        ACQUISITION_LABELS[item.acquisition],
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                {item.note && (
                                    <span className="block text-slate-500">
                                        {item.note}
                                    </span>
                                )}
                            </span>
                            <span className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setEditing(item);
                                        setFormKey((k) => k + 1);
                                    }}
                                >
                                    <Pencil aria-hidden /> შეცვლა
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(
                                            `/portal/book-list-items/${item.id}`,
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    toast.success(
                                                        'წიგნი ამოიშალა.',
                                                    ),
                                            },
                                        )
                                    }
                                >
                                    <Trash2 aria-hidden /> წაშლა
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <form
                key={formKey}
                onSubmit={submit}
                className="grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-2"
                aria-labelledby="item-form"
            >
                <h2 id="item-form" className="text-lg sm:col-span-2">
                    {editing ? `შეცვლა: ${editing.title}` : 'წიგნის დამატება'}
                </h2>
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="item-title">სათაური</Label>
                    <Input
                        id="item-title"
                        name="title"
                        required
                        maxLength={255}
                        defaultValue={editing?.title}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="item-author">ავტორი</Label>
                    <Input
                        id="item-author"
                        name="author"
                        maxLength={255}
                        defaultValue={editing?.author ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="item-edition">გამოცემა</Label>
                    <Input
                        id="item-edition"
                        name="edition"
                        maxLength={120}
                        defaultValue={editing?.edition ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="item-subject">საგანი</Label>
                    <Input
                        id="item-subject"
                        name="subject"
                        maxLength={120}
                        defaultValue={editing?.subject ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="item-isbn">ISBN (არასავალდებულო)</Label>
                    <Input
                        id="item-isbn"
                        name="isbn"
                        maxLength={20}
                        pattern="[0-9Xx\-]+"
                        defaultValue={editing?.isbn ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="item-requirement">სტატუსი</Label>
                    <select
                        id="item-requirement"
                        name="requirement"
                        className={selectClass}
                        defaultValue={editing?.requirement ?? 'required'}
                    >
                        {Object.entries(REQUIREMENT_LABELS).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="item-acquisition">როგორ შეიძინოს</Label>
                    <select
                        id="item-acquisition"
                        name="acquisition"
                        className={selectClass}
                        defaultValue={editing?.acquisition ?? 'purchase'}
                    >
                        {Object.entries(ACQUISITION_LABELS).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </div>
                {catalog.length > 0 && (
                    <div className="grid gap-1 sm:col-span-2">
                        <Label htmlFor="item-resource">
                            ბიბლიოთეკის კატალოგში (არასავალდებულო)
                        </Label>
                        <select
                            id="item-resource"
                            name="library_resource_id"
                            className={selectClass}
                            defaultValue={editing?.resourceId ?? ''}
                        >
                            <option value="">— არ არის მიბმული —</option>
                            {catalog.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="item-note">შენიშვნა</Label>
                    <Input
                        id="item-note"
                        name="note"
                        maxLength={500}
                        defaultValue={editing?.note ?? ''}
                    />
                </div>
                <div className="flex flex-wrap gap-2 sm:col-span-2">
                    <Button type="submit">
                        {editing ? 'შენახვა' : 'დამატება'}
                    </Button>
                    {editing && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setEditing(null);
                                setFormKey((k) => k + 1);
                            }}
                        >
                            გაუქმება
                        </Button>
                    )}
                </div>
            </form>
        </PortalLayout>
    );
}
