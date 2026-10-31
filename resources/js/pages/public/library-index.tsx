import { Head, Link, router } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    BookOpen,
    Search,
} from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { Input } from '@/components/ui/input';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { Button } from '@/components/ui/button';

type LibraryResource = {
    id: number;
    title: string;
    author: string | null;
    grade: string | null;
    subject: string | null;
    isRequired: boolean;
    accessScope: string;
    externalUrl: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    resources: Paginated<LibraryResource>;
    query: string;
};

const scopeLabel: Record<string, string> = {
    catalog_only: 'მხოლოდ კატალოგში',
    loan: 'ბიბლიოთეკიდან სესხება',
    digital: 'ციფრული წვდომა',
};

export default function LibraryIndex({ resources, query }: Props) {
    const [search, setSearch] = useState(query);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    // The query this page last asked the server for.
    const sentRef = useRef(query);

    // Back/forward or a link changed the query: show it in the box.
    useEffect(() => {
        if (query !== sentRef.current) {
            sentRef.current = query;
            setSearch(query);
        }
    }, [query]);

    useEffect(() => {
        // Only a typed change searches, so the page already shown (a deep
        // link such as ?page=3, or one restored by back/forward) stays put.
        if (search.trim() === sentRef.current) return;
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            sentRef.current = search.trim();
            router.get('/library', search.trim() ? { q: search.trim() } : {}, {
                preserveState: true,
                replace: true,
            });
        }, 300);

        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
    }, [search]);

    return (
        <PublicLayout>
            <Head title="ბიბლიოთეკის კატალოგი — აისი" />

            <section className="mx-auto max-w-5xl px-6 py-16 sm:py-24">
                <p className="mb-4 text-xs font-bold tracking-widest text-slate-500">
                    რესურსები
                </p>
                <h1 className="mb-8 text-3xl sm:text-4xl">
                    ბიბლიოთეკის კატალოგი
                </h1>

                <label className="mb-10 flex max-w-md items-center gap-3 rounded-lg border border-slate-200 px-4 py-3">
                    <Search size={20} className="text-slate-400" />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="მოძებნე წიგნი, ავტორი ან საგანი"
                        aria-label="წიგნის ძიება"
                        className="border-0 p-0 shadow-none focus-visible:ring-0"
                    />
                </label>

                {resources.data.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <Search />
                            <EmptyTitle>რესურსი ვერ მოიძებნა</EmptyTitle>
                            <EmptyDescription>
                                სცადე სხვა საგნის ან ავტორის სახელით ძებნა.
                            </EmptyDescription>
                        </EmptyHeader>
                        {search && (
                            <Button
                                variant="outline"
                                onClick={() => setSearch('')}
                            >
                                ძიების გასუფთავება
                            </Button>
                        )}
                    </Empty>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {resources.data.map((resource) => (
                            <li
                                key={resource.id}
                                className="rounded-xl border border-slate-200 p-5"
                            >
                                <BookOpen
                                    aria-hidden
                                    className="mb-3 h-8 w-8 text-[var(--brand-accent,#F5683C)]"
                                />
                                <h2 className="font-body text-lg font-semibold">
                                    {resource.title}
                                </h2>
                                {resource.author && (
                                    <p className="text-sm text-slate-600">
                                        {resource.author}
                                    </p>
                                )}
                                <div className="mt-3 flex flex-wrap gap-2 text-xs text-slate-600">
                                    {resource.grade && (
                                        <span className="rounded-full bg-slate-100 px-2 py-1">
                                            {resource.grade}
                                        </span>
                                    )}
                                    {resource.subject && (
                                        <span className="rounded-full bg-slate-100 px-2 py-1">
                                            {resource.subject}
                                        </span>
                                    )}
                                    {resource.isRequired && (
                                        <span className="rounded-full bg-slate-100 px-2 py-1">
                                            სავალდებულო
                                        </span>
                                    )}
                                </div>
                                <p className="mt-3 text-xs text-slate-600">
                                    {scopeLabel[resource.accessScope] ??
                                        resource.accessScope}
                                </p>
                                {/* The server sends a link only for a rights-confirmed digital item. */}
                                {resource.externalUrl && (
                                    <a
                                        href={resource.externalUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-2 inline-flex min-h-11 items-center gap-1 text-sm font-semibold underline-offset-4 hover:underline"
                                    >
                                        გახსნა
                                        <span className="sr-only">
                                            : {resource.title} (ახალ ფანჯარაში)
                                        </span>
                                        <ArrowUpRight size={16} aria-hidden />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {resources.last_page > 1 && (
                    <nav
                        aria-label="კატალოგის გვერდები"
                        className="mt-10 flex flex-wrap items-center justify-between gap-4"
                    >
                        <p className="text-sm text-slate-600">
                            {resources.from}–{resources.to} / {resources.total}
                            {' · '}გვერდი {resources.current_page} /{' '}
                            {resources.last_page}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <PageLink href={resources.prev_page_url} rel="prev">
                                <ArrowLeft size={16} aria-hidden /> წინა
                            </PageLink>
                            {resources.links.slice(1, -1).map((link, index) =>
                                link.url === null ? (
                                    <span
                                        key={`gap-${index}`}
                                        className="px-2 text-slate-500"
                                    >
                                        {link.label}
                                    </span>
                                ) : (
                                    <PageLink
                                        key={link.label}
                                        href={link.url}
                                        current={link.active}
                                        label={`გვერდი ${link.label}`}
                                    >
                                        {link.label}
                                    </PageLink>
                                ),
                            )}
                            <PageLink href={resources.next_page_url} rel="next">
                                შემდეგი <ArrowRight size={16} aria-hidden />
                            </PageLink>
                        </div>
                    </nav>
                )}
            </section>
        </PublicLayout>
    );
}

function PageLink({
    href,
    current = false,
    rel,
    label,
    children,
}: {
    href: string | null;
    current?: boolean;
    rel?: string;
    label?: string;
    children: ReactNode;
}) {
    const className =
        'inline-flex min-h-11 min-w-11 items-center justify-center gap-1 rounded-lg px-3 text-sm font-semibold ring-1 ring-slate-200';

    if (href === null) {
        return (
            <span
                aria-disabled="true"
                className={`${className} text-slate-400`}
            >
                {children}
            </span>
        );
    }

    if (current) {
        return (
            <span
                aria-current="page"
                aria-label={label}
                className={`${className} bg-[var(--brand-primary)] text-white ring-[var(--brand-primary)]`}
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            rel={rel}
            aria-label={label}
            className={`${className} hover:bg-slate-50`}
        >
            {children}
        </Link>
    );
}
