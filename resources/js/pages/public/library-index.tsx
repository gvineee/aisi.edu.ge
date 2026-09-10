import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { BookOpen, Search } from 'lucide-react';
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
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    resources: Paginated<LibraryResource>;
    query: string;
};

const scopeLabel: Record<string, string> = {
    catalog_only: 'კატალოგში',
    loan: 'ბიბლიოთეკიდან სესხება',
    digital: 'ციფრული წვდომა',
};

export default function LibraryIndex({ resources, query }: Props) {
    const [search, setSearch] = useState(query);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            router.get('/library', search ? { q: search } : {}, {
                preserveState: true,
                replace: true,
            });
        }, 300);

        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {resources.data.map((resource) => (
                            <div
                                key={resource.id}
                                className="rounded-xl border border-slate-200 p-5"
                            >
                                <BookOpen className="mb-3 h-8 w-8 text-[var(--brand-accent,#F5683C)]" />
                                <h3 className="text-lg">{resource.title}</h3>
                                {resource.author && (
                                    <p className="text-sm text-slate-500">
                                        {resource.author}
                                    </p>
                                )}
                                <div className="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
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
                                <p className="mt-3 text-xs text-slate-400">
                                    {scopeLabel[resource.accessScope] ??
                                        resource.accessScope}
                                </p>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        </PublicLayout>
    );
}
