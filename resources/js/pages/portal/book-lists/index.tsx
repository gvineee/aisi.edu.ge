import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/layouts/portal/portal-layout';

type Props = {
    classes: {
        id: number;
        name: string;
        yearName: string | null;
        isCurrentYear: boolean;
        itemsCount: number;
    }[];
};

/**
 * Classes whose book list this user may edit: every class for the library
 * desk, own classes for a teacher. Current year first.
 */
export default function BookListsIndex({ classes }: Props) {
    return (
        <PortalLayout>
            <Head title="წიგნების სიები" />
            <h1 className="mb-1 text-2xl">წიგნების სიები</h1>
            <p className="mb-6 text-sm text-slate-600">
                კლასის სია ჩანს ამ კლასის მოსწავლეებისა და მშობლების გვერდზე
                „წიგნები“.
            </p>
            {classes.length === 0 ? (
                <p className="rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
                    კლასი ჯერ არ არის შექმნილი.
                </p>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {classes.map((c) => (
                        <li key={c.id}>
                            <Link
                                href={`/portal/book-lists/${c.id}`}
                                className={`flex min-h-11 flex-col rounded-xl bg-white p-4 ring-1 ring-slate-200 hover:bg-slate-50 ${c.isCurrentYear ? '' : 'opacity-70'}`}
                            >
                                <span className="font-medium">{c.name}</span>
                                <span className="text-sm text-slate-600">
                                    {c.yearName}
                                    {c.yearName && ' · '}
                                    {c.itemsCount === 0
                                        ? 'სია ცარიელია'
                                        : `${c.itemsCount} წიგნი`}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
