import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';

type PageSummary = {
    id: number;
    slug: string;
    locale: string;
    title: string;
    status: string;
    publishedAt: string | null;
    updatedAt: string | null;
};

type Props = {
    pages: PageSummary[];
    filters: { status: string };
    canPublish: boolean;
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'მონახაზი',
    published: 'გამოქვეყნებული',
};

export default function CmsPagesIndex({ pages, filters }: Props) {
    const applyFilter = (status: string) => {
        router.get(
            '/portal/cms/pages',
            { status },
            { preserveState: true, replace: true },
        );
    };

    return (
        <PortalLayout>
            <Head title="CMS — გვერდები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">გვერდები</h1>
                <Link href="/portal/cms/pages/create">
                    <Button>
                        <Plus size={16} /> ახალი გვერდი
                    </Button>
                </Link>
            </div>

            <div className="mb-4 flex gap-3">
                <div className="flex gap-1">
                    <Link
                        href="/portal/cms/posts"
                        className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium"
                    >
                        ამბები
                    </Link>
                    <Link
                        href="/portal/cms/media"
                        className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium"
                    >
                        მედია
                    </Link>
                </div>
                <select
                    value={filters.status}
                    onChange={(event) => applyFilter(event.target.value)}
                    className="h-9 rounded-md border border-slate-300 px-3 text-sm"
                >
                    <option value="">ყველა სტატუსი</option>
                    {Object.entries(STATUS_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>

            {pages.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <FileText className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">გვერდი ვერ მოიძებნა.</p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {pages.map((page) => (
                        <li key={page.id} className="p-4">
                            <Link
                                href={`/portal/cms/pages/${page.id}/edit`}
                                className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {page.title}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        /{page.slug} · {page.locale}
                                    </p>
                                </div>
                                <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                                    {STATUS_LABELS[page.status] ??
                                        page.status}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
