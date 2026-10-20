import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { BookPlus, HandHelping, Search } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SCOPE_LABELS } from '@/lib/library';
import {
    LoanList,
    ReservationList,
    deskOptions,
    type LoanRow,
    type ReservationRow,
} from '@/components/library/desk-lists';

type Props = {
    tab: 'loans' | 'catalog' | 'reservations';
    filters: { q: string; overdue: boolean };
    today: string;
    loanDays: number;
    counts: { active: number; overdue: number; ready: number; waiting: number };
    students: { id: number; name: string; className: string | null }[];
    staff: { id: number; name: string }[];
    loans: LoanRow[];
    resources: {
        id: number;
        title: string;
        author: string | null;
        grade: string | null;
        subject: string | null;
        accessScope: string;
        copiesTotal: number;
        copiesAvailable: number;
    }[];
    reservations: ReservationRow[];
};

const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

function addDays(isoDate: string, days: number): string {
    const date = new Date(`${isoDate}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

/**
 * The librarian's desk: lend by inventory number, the active/overdue
 * loans with return/renew/lost, the reservation queue and the catalog.
 */
export default function LibraryDesk(props: Props) {
    const { tab, filters, counts } = props;
    const [search, setSearch] = useState(filters.q);
    const tabHref = (key: string) =>
        key === 'loans'
            ? '/portal/library-desk'
            : `/portal/library-desk?tab=${key}`;

    return (
        <PortalLayout>
            <Head title="ბიბლიოთეკა" />
            <h1 className="mb-4 text-2xl">ბიბლიოთეკა</h1>

            <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {[
                    ['გაცემული', counts.active],
                    ['ვადაგადაცილებული', counts.overdue],
                    ['გადადებული', counts.ready],
                    ['რიგში', counts.waiting],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-xl bg-white p-3 ring-1 ring-slate-200"
                    >
                        <dt className="text-xs text-slate-500">{label}</dt>
                        <dd className="text-xl font-semibold">{value}</dd>
                    </div>
                ))}
            </dl>

            <LendForm {...props} />

            <nav
                aria-label="ბიბლიოთეკის განყოფილებები"
                className="mb-4 flex flex-wrap gap-2"
            >
                {(
                    [
                        ['loans', 'სესხები'],
                        ['reservations', 'ჯავშნები'],
                        ['catalog', 'კატალოგი'],
                    ] as const
                ).map(([key, label]) => (
                    <Link
                        key={key}
                        href={tabHref(key)}
                        aria-current={tab === key ? 'page' : undefined}
                        className={`inline-flex min-h-11 items-center rounded-lg px-4 text-sm ring-1 ring-slate-200 ${tab === key ? 'bg-slate-900 text-white' : 'bg-white hover:bg-slate-50'}`}
                    >
                        {label}
                    </Link>
                ))}
            </nav>

            {tab !== 'reservations' && (
                <form
                    role="search"
                    className="mb-4 flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/portal/library-desk',
                            {
                                ...(tab === 'catalog' ? { tab } : {}),
                                ...(search ? { q: search } : {}),
                                ...(filters.overdue && tab === 'loans'
                                    ? { overdue: 1 }
                                    : {}),
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <label htmlFor="desk-search" className="sr-only">
                        ძებნა
                    </label>
                    <Input
                        id="desk-search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={
                            tab === 'loans'
                                ? 'სათაური, ინვენტარის № ან მოსწავლე'
                                : 'სათაური, ავტორი ან ISBN'
                        }
                        className="min-w-0 flex-1"
                    />
                    <Button type="submit" variant="outline">
                        <Search aria-hidden /> ძებნა
                    </Button>
                    {tab === 'loans' && (
                        <Link
                            href={
                                filters.overdue
                                    ? '/portal/library-desk'
                                    : '/portal/library-desk?overdue=1'
                            }
                            className="inline-flex min-h-11 items-center rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                        >
                            {filters.overdue
                                ? 'ყველა სესხი'
                                : 'მხოლოდ ვადაგადაცილებული'}
                        </Link>
                    )}
                </form>
            )}

            {tab === 'loans' && <LoanList loans={props.loans} />}
            {tab === 'reservations' && (
                <ReservationList reservations={props.reservations} />
            )}
            {tab === 'catalog' && <Catalog resources={props.resources} />}
        </PortalLayout>
    );
}

function LendForm({ students, staff, today, loanDays }: Props) {
    const [borrowerType, setBorrowerType] = useState<'student' | 'staff'>(
        'student',
    );
    const [formKey, setFormKey] = useState(0);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.post(
            '/portal/library-desk/loans',
            Object.fromEntries(new FormData(event.currentTarget)),
            deskOptions('გაცემულია.', () => setFormKey((k) => k + 1)),
        );
    };

    return (
        <form
            key={formKey}
            onSubmit={submit}
            className="mb-6 grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-4"
            aria-labelledby="lend-form"
        >
            <h2 id="lend-form" className="text-lg sm:col-span-4">
                წიგნის გაცემა
            </h2>
            <div className="grid gap-1">
                <Label htmlFor="lend-inventory">ინვენტარის №</Label>
                <Input
                    id="lend-inventory"
                    name="inventory_number"
                    required
                    maxLength={40}
                    autoComplete="off"
                />
            </div>
            <fieldset className="grid gap-1 sm:col-span-2">
                <legend className="mb-1 text-sm font-medium">მსესხებელი</legend>
                <div className="flex gap-2">
                    <select
                        aria-label="მსესხებლის ტიპი"
                        value={borrowerType}
                        onChange={(e) =>
                            setBorrowerType(
                                e.target.value as 'student' | 'staff',
                            )
                        }
                        className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                    >
                        <option value="student">მოსწავლე</option>
                        <option value="staff">თანამშრომელი</option>
                    </select>
                    {borrowerType === 'student' ? (
                        <select
                            name="student_id"
                            required
                            aria-label="მოსწავლე"
                            className={selectClass}
                            defaultValue=""
                        >
                            <option value="" disabled>
                                აირჩიეთ მოსწავლე
                            </option>
                            {students.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                    {s.className ? ` — ${s.className}` : ''}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <select
                            name="user_id"
                            required
                            aria-label="თანამშრომელი"
                            className={selectClass}
                            defaultValue=""
                        >
                            <option value="" disabled>
                                აირჩიეთ თანამშრომელი
                            </option>
                            {staff.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
            </fieldset>
            <div className="grid gap-1">
                <Label htmlFor="lend-due">დაბრუნების ვადა</Label>
                <Input
                    id="lend-due"
                    name="due_on"
                    type="date"
                    required
                    min={today}
                    defaultValue={addDays(today, loanDays)}
                />
            </div>
            <div className="sm:col-span-4">
                <Button type="submit">
                    <HandHelping aria-hidden /> გაცემა
                </Button>
            </div>
        </form>
    );
}

function Catalog({ resources }: { resources: Props['resources'] }) {
    const [formKey, setFormKey] = useState(0);

    return (
        <>
            <form
                key={formKey}
                className="mb-4 grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-3"
                aria-labelledby="new-resource"
                onSubmit={(e) => {
                    e.preventDefault();
                    const data = Object.fromEntries(
                        new FormData(e.currentTarget),
                    );
                    router.post(
                        '/portal/library-desk/resources',
                        { ...data, is_required: data.is_required === 'on' },
                        deskOptions('წიგნი დაემატა.', () =>
                            setFormKey((k) => k + 1),
                        ),
                    );
                }}
            >
                <h2 id="new-resource" className="text-lg sm:col-span-3">
                    ახალი წიგნი კატალოგში
                </h2>
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="res-title">სათაური</Label>
                    <Input
                        id="res-title"
                        name="title"
                        required
                        maxLength={255}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="res-author">ავტორი</Label>
                    <Input id="res-author" name="author" maxLength={255} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="res-isbn">ISBN</Label>
                    <Input
                        id="res-isbn"
                        name="isbn"
                        maxLength={20}
                        pattern="[0-9Xx\-]+"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="res-grade">კლასი</Label>
                    <Input id="res-grade" name="grade" maxLength={60} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="res-subject">საგანი</Label>
                    <Input id="res-subject" name="subject" maxLength={120} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="res-scope">გაცემა</Label>
                    <select
                        id="res-scope"
                        name="access_scope"
                        className={selectClass}
                        defaultValue="loan"
                    >
                        {Object.entries(SCOPE_LABELS).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </div>
                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="is_required"
                        className="size-4"
                    />{' '}
                    სავალდებულო საკითხავი
                </label>
                <div className="flex items-end">
                    <Button type="submit">
                        <BookPlus aria-hidden /> დამატება
                    </Button>
                </div>
            </form>

            {resources.length === 0 ? (
                <p className="rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
                    კატალოგში ვერაფერი მოიძებნა.
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                    {resources.map((r) => (
                        <li key={r.id}>
                            <Link
                                href={`/portal/library-desk/resources/${r.id}`}
                                className="flex min-h-11 flex-wrap items-center justify-between gap-2 p-3 hover:bg-slate-50"
                            >
                                <span>
                                    <span className="block font-medium">
                                        {r.title}
                                    </span>
                                    <span className="block text-slate-600">
                                        {[
                                            r.author,
                                            r.grade,
                                            r.subject,
                                            SCOPE_LABELS[r.accessScope],
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <span className="text-slate-600">
                                    {r.copiesTotal === 0
                                        ? 'ეგზემპლარი არ არის'
                                        : `თაროზე ${r.copiesAvailable} / ${r.copiesTotal}`}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
