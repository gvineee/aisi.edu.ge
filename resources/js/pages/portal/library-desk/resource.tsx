import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { COPY_STATUS_LABELS, SCOPE_LABELS, formatDate } from '@/lib/library';
import {
    LoanList,
    ReservationList,
    deskOptions,
    type LoanRow,
    type ReservationRow,
} from '@/components/library/desk-lists';

type Props = {
    resource: {
        id: number;
        title: string;
        author: string | null;
        isbn: string | null;
        grade: string | null;
        subject: string | null;
        isRequired: boolean;
        accessScope: string;
        externalUrl: string | null;
    };
    copies: {
        id: number;
        inventoryNumber: string;
        status: string;
        borrower: string | null;
        dueOn: string | null;
    }[];
    loans: LoanRow[];
    reservations: ReservationRow[];
};

const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

/**
 * One catalog title at the desk: details, physical copies with their
 * state, the reservation queue and loan history.
 */
export default function LibraryResourcePage({
    resource,
    copies,
    loans,
    reservations,
}: Props) {
    const [editKey, setEditKey] = useState(0);
    const [copiesKey, setCopiesKey] = useState(0);

    return (
        <PortalLayout>
            <Head title={resource.title} />
            <Link
                href="/portal/library-desk?tab=catalog"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> კატალოგი
            </Link>
            <h1 className="mb-1 text-2xl">{resource.title}</h1>
            <p className="mb-6 text-sm text-slate-600">
                {[
                    resource.author,
                    resource.grade,
                    resource.subject,
                    resource.isbn && `ISBN ${resource.isbn}`,
                    SCOPE_LABELS[resource.accessScope],
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </p>

            <section className="mb-6" aria-labelledby="copies">
                <h2 id="copies" className="mb-2 text-lg">
                    ეგზემპლარები
                </h2>
                {copies.length === 0 ? (
                    <p className="mb-3 text-sm text-slate-500">
                        ეგზემპლარი ჯერ არ არის.
                    </p>
                ) : (
                    <ul className="mb-3 divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                        {copies.map((c) => (
                            <li
                                key={c.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-3"
                            >
                                <span>
                                    <strong>№ {c.inventoryNumber}</strong> ·{' '}
                                    {COPY_STATUS_LABELS[c.status] ?? c.status}
                                    {c.borrower && ` · ${c.borrower}`}
                                    {c.dueOn && `, ვადა ${formatDate(c.dueOn)}`}
                                </span>
                                {(c.status === 'available' ||
                                    c.status === 'lost') && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/portal/library-desk/copies/${c.id}/withdraw`,
                                                {},
                                                deskOptions('ჩამოიწერა.'),
                                            )
                                        }
                                    >
                                        ჩამოწერა
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    key={copiesKey}
                    className="flex flex-wrap items-end gap-2 rounded-xl bg-white p-4 ring-1 ring-slate-200"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/portal/library-desk/resources/${resource.id}/copies`,
                            Object.fromEntries(new FormData(e.currentTarget)),
                            deskOptions('ეგზემპლარები დაემატა.', () =>
                                setCopiesKey((k) => k + 1),
                            ),
                        );
                    }}
                >
                    <div className="grid gap-1">
                        <Label htmlFor="copy-count">რაოდენობა</Label>
                        <Input
                            id="copy-count"
                            name="count"
                            type="number"
                            min={1}
                            max={100}
                            defaultValue={1}
                            className="w-28"
                        />
                    </div>
                    <div className="grid min-w-[220px] flex-1 gap-1">
                        <Label htmlFor="copy-numbers">
                            ან ინვენტარის ნომრები (ცარიელი — ავტომატურად)
                        </Label>
                        <Input
                            id="copy-numbers"
                            name="inventory_numbers"
                            maxLength={4000}
                            placeholder="მაგ. B-101, B-102"
                        />
                    </div>
                    <Button type="submit">ეგზემპლარის დამატება</Button>
                </form>
            </section>

            <section className="mb-6" aria-labelledby="queue">
                <h2 id="queue" className="mb-2 text-lg">
                    ჯავშნები
                </h2>
                <ReservationList reservations={reservations} />
            </section>

            <section className="mb-6" aria-labelledby="history">
                <h2 id="history" className="mb-2 text-lg">
                    სესხების ისტორია
                </h2>
                <LoanList loans={loans} />
            </section>

            <form
                key={editKey}
                className="grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-3"
                aria-labelledby="edit-resource"
                onSubmit={(e) => {
                    e.preventDefault();
                    const data = Object.fromEntries(
                        new FormData(e.currentTarget),
                    );
                    router.put(
                        `/portal/library-desk/resources/${resource.id}`,
                        { ...data, is_required: data.is_required === 'on' },
                        deskOptions('შენახულია.', () =>
                            setEditKey((k) => k + 1),
                        ),
                    );
                }}
            >
                <h2 id="edit-resource" className="text-lg sm:col-span-3">
                    მონაცემების შეცვლა
                </h2>
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="edit-title">სათაური</Label>
                    <Input
                        id="edit-title"
                        name="title"
                        required
                        maxLength={255}
                        defaultValue={resource.title}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-author">ავტორი</Label>
                    <Input
                        id="edit-author"
                        name="author"
                        maxLength={255}
                        defaultValue={resource.author ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-isbn">ISBN</Label>
                    <Input
                        id="edit-isbn"
                        name="isbn"
                        maxLength={20}
                        pattern="[0-9Xx\-]+"
                        defaultValue={resource.isbn ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-grade">კლასი</Label>
                    <Input
                        id="edit-grade"
                        name="grade"
                        maxLength={60}
                        defaultValue={resource.grade ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-subject">საგანი</Label>
                    <Input
                        id="edit-subject"
                        name="subject"
                        maxLength={120}
                        defaultValue={resource.subject ?? ''}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="edit-scope">გაცემა</Label>
                    <select
                        id="edit-scope"
                        name="access_scope"
                        className={selectClass}
                        defaultValue={resource.accessScope}
                    >
                        {Object.entries(SCOPE_LABELS).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="edit-url">გარე ბმული</Label>
                    <Input
                        id="edit-url"
                        name="external_url"
                        type="url"
                        maxLength={2048}
                        defaultValue={resource.externalUrl ?? ''}
                    />
                </div>
                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="is_required"
                        defaultChecked={resource.isRequired}
                        className="size-4"
                    />{' '}
                    სავალდებულო საკითხავი
                </label>
                <div className="flex items-end sm:col-span-2">
                    <Button type="submit">შენახვა</Button>
                </div>
            </form>
        </PortalLayout>
    );
}
