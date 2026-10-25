import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

export type CategoryBreadcrumb = { name: string; href: string };

export type CategorySectionData = {
    name: string;
    items: {
        title: string;
        url: string;
        date: string | null;
        excerpt: string | null;
        section: string | null;
    }[];
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('ka-GE', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Tbilisi',
    });

/** Link back to the section a page or post belongs to. */
export function CategoryBreadcrumbNav({
    breadcrumb,
}: {
    breadcrumb: CategoryBreadcrumb;
}) {
    return (
        <nav aria-label="განყოფილება" className="mx-auto max-w-3xl px-6 pt-8">
            <Link
                href={breadcrumb.href}
                className="inline-flex min-h-11 items-center gap-1 text-sm text-slate-600 hover:underline"
            >
                <ChevronLeft size={16} aria-hidden />
                {breadcrumb.name}
            </Link>
        </nav>
    );
}

/**
 * The published pages and posts placed in this hub's category, each with
 * its own original date and URL.
 */
export function CategorySection({ section }: { section: CategorySectionData }) {
    return (
        <section
            aria-labelledby="category-section"
            className="mx-auto max-w-3xl px-6 py-12"
        >
            <h2 id="category-section" className="mb-6 text-2xl">
                {section.name}: მასალები
            </h2>
            <ul className="divide-y divide-slate-100 rounded-2xl bg-white ring-1 ring-slate-200">
                {section.items.map((item) => (
                    <li key={item.url}>
                        <Link
                            href={item.url}
                            className="block px-5 py-4 hover:bg-slate-50"
                        >
                            <span className="block font-medium">
                                {item.title}
                            </span>
                            {item.date && (
                                <time
                                    dateTime={item.date}
                                    className="text-sm text-slate-500"
                                >
                                    {formatDate(item.date)}
                                </time>
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
