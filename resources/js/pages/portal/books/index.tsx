import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BookOpen, Search } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDateTime } from '@/lib/assessment';
import {
    ACQUISITION_LABELS,
    REQUIREMENT_LABELS,
    formatDate,
    type BookListItemRow,
} from '@/lib/library';

type StudentBooks = {
    id: number;
    name: string;
    className: string | null;
    bookList: BookListItemRow[];
    loans: {
        id: number;
        resourceId: number;
        title: string;
        dueOn: string;
        overdue: boolean;
    }[];
    reservations: {
        id: number;
        resourceId: number;
        title: string;
        status: 'waiting' | 'ready';
        readyUntil: string | null;
        position: number | null;
    }[];
};

type Props = {
    libraryOn: boolean;
    query: string;
    catalog: {
        id: number;
        title: string;
        author: string | null;
        grade: string | null;
        available: number;
    }[];
    students: StudentBooks[];
};

const options = (success: string) => ({
    preserveScroll: true,
    onSuccess: () => toast.success(success),
    onError: (errors: Record<string, string>) =>
        toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
});

/**
 * „წიგნები“ for families and students: the class book list, books taken
 * from the school library with due dates, reservations, and the loanable
 * catalog to reserve from (when the school runs its library here).
 */
export default function BooksIndex({
    libraryOn,
    query,
    catalog,
    students,
}: Props) {
    const [search, setSearch] = useState(query);
    const [reserveFor, setReserveFor] = useState(String(students[0]?.id ?? ''));
    // Titles the selected student already has or is queued for.
    const selected = students.find((s) => String(s.id) === reserveFor);
    const holding = new Map<number, string>([
        ...(selected?.reservations ?? []).map(
            (r) => [r.resourceId, 'დაჯავშნილია'] as [number, string],
        ),
        ...(selected?.loans ?? []).map(
            (l) => [l.resourceId, 'გატანილი აქვს'] as [number, string],
        ),
    ]);

    return (
        <PortalLayout>
            <Head title="წიგნები" />
            <h1 className="mb-6 text-2xl">წიგნები</h1>

            {students.map((student) => (
                <section
                    key={student.id}
                    className="mb-8 space-y-4"
                    aria-labelledby={`student-${student.id}`}
                >
                    <h2 id={`student-${student.id}`} className="text-xl">
                        {student.name}
                        {student.className && (
                            <span className="text-base text-slate-500">
                                {' '}
                                · {student.className}
                            </span>
                        )}
                    </h2>

                    <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                        <h3 className="mb-2 font-medium">
                            კლასის წიგნების სია
                        </h3>
                        {student.bookList.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                სია ჯერ არ გამოქვეყნებულა.
                            </p>
                        ) : (
                            <ul className="divide-y divide-slate-100 text-sm">
                                {student.bookList.map((item) => (
                                    <li key={item.id} className="py-2">
                                        <p className="font-medium">
                                            {item.title}
                                            {item.edition &&
                                                ` (${item.edition})`}
                                        </p>
                                        <p className="text-slate-600">
                                            {[
                                                item.author,
                                                item.subject,
                                                item.isbn &&
                                                    `ISBN ${item.isbn}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        <p className="text-slate-600">
                                            <span
                                                className={
                                                    item.requirement ===
                                                    'required'
                                                        ? 'font-medium text-slate-900'
                                                        : ''
                                                }
                                            >
                                                {
                                                    REQUIREMENT_LABELS[
                                                        item.requirement
                                                    ]
                                                }
                                            </span>{' '}
                                            ·{' '}
                                            {
                                                ACQUISITION_LABELS[
                                                    item.acquisition
                                                ]
                                            }
                                            {item.note && ` · ${item.note}`}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {libraryOn && (
                        <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                            <h3 className="mb-2 font-medium">ბიბლიოთეკიდან</h3>
                            {student.loans.length === 0 &&
                                student.reservations.length === 0 && (
                                    <p className="text-sm text-slate-500">
                                        გატანილი ან დაჯავშნილი წიგნი არ არის.
                                    </p>
                                )}
                            <ul className="space-y-2 text-sm">
                                {student.loans.map((loan) => (
                                    <li key={`l${loan.id}`}>
                                        <strong>{loan.title}</strong> ·
                                        დაბრუნება {formatDate(loan.dueOn)}
                                        {loan.overdue && (
                                            <span className="ml-2 rounded bg-red-50 px-2 py-0.5 text-red-800">
                                                ვადა გავიდა
                                            </span>
                                        )}
                                    </li>
                                ))}
                                {student.reservations.map((r) => (
                                    <li
                                        key={`r${r.id}`}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <span>
                                            <strong>{r.title}</strong> ·{' '}
                                            {r.status === 'ready'
                                                ? `გელოდებათ ბიბლიოთეკაში${r.readyUntil ? ` ${formatDateTime(r.readyUntil)}-მდე` : ''}`
                                                : `რიგში: ${r.position ?? '—'}`}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/portal/books/reservations/${r.id}/cancel`,
                                                    {},
                                                    options('ჯავშანი გაუქმდა.'),
                                                )
                                            }
                                        >
                                            გაუქმება
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>
            ))}

            {libraryOn && (
                <section aria-labelledby="catalog" className="space-y-3">
                    <h2 id="catalog" className="text-xl">
                        ბიბლიოთეკის კატალოგი
                    </h2>
                    <form
                        role="search"
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/portal/books',
                                search ? { q: search } : {},
                                { preserveScroll: true, preserveState: true },
                            );
                        }}
                    >
                        <label htmlFor="book-search" className="sr-only">
                            ძებნა სათაურით ან ავტორით
                        </label>
                        <Input
                            id="book-search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="სათაური ან ავტორი"
                            className="min-w-0 flex-1"
                        />
                        <Button type="submit" variant="outline">
                            <Search aria-hidden /> ძებნა
                        </Button>
                    </form>
                    {students.length > 1 && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <label htmlFor="reserve-for">ვისთვის:</label>
                            <select
                                id="reserve-for"
                                value={reserveFor}
                                onChange={(e) => setReserveFor(e.target.value)}
                                className="h-11 rounded-md border border-slate-300 bg-white px-3"
                            >
                                {students.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    {catalog.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            {query
                                ? 'ასეთი წიგნი ვერ მოიძებნა.'
                                : 'გასაცემი წიგნები ჯერ არ არის.'}
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                            {catalog.map((book) => (
                                <li
                                    key={book.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <span>
                                        <BookOpen
                                            size={14}
                                            className="mr-1 inline"
                                            aria-hidden
                                        />
                                        <strong>{book.title}</strong>
                                        {book.author && ` · ${book.author}`}
                                        <span className="block text-slate-600">
                                            {book.available > 0
                                                ? `თაროზეა: ${book.available}`
                                                : 'ყველა ეგზემპლარი გაცემულია — შეგიძლიათ რიგში ჩაეწეროთ'}
                                        </span>
                                    </span>
                                    {holding.has(book.id) ? (
                                        <span className="text-slate-600">
                                            {holding.get(book.id)}
                                        </span>
                                    ) : (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    '/portal/books/reservations',
                                                    {
                                                        resource_id: book.id,
                                                        student_id: reserveFor,
                                                    },
                                                    options('დაჯავშნილია.'),
                                                )
                                            }
                                        >
                                            დაჯავშნა
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </PortalLayout>
    );
}
