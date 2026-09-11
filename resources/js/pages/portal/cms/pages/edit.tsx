import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Copy, Eye, History, Send, Undo2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type PageDetail = {
    id: number;
    slug: string;
    locale: string;
    title: string;
    excerpt: string | null;
    blocks: Array<Record<string, unknown>>;
    status: string;
    publishedAt: string | null;
    seoTitle: string | null;
    seoDescription: string | null;
};

type Revision = {
    id: number;
    title: string;
    status: string;
    authorName: string | null;
    createdAt: string | null;
};

type MediaItem = {
    id: number;
    path: string;
    url: string;
    originalFilename: string;
    altText: string | null;
};

type Props = {
    page: PageDetail | null;
    revisions: Revision[];
    media: MediaItem[];
    canPublish: boolean;
};

const DEFAULT_BLOCKS = '[\n  { "type": "text", "heading": "სათაური", "body": "ტექსტი" }\n]';

export default function CmsPageEdit({ page, revisions, media, canPublish }: Props) {
    const [saving, setSaving] = useState(false);
    const [blocksValue, setBlocksValue] = useState(
        page ? JSON.stringify(page.blocks, null, 2) : DEFAULT_BLOCKS,
    );

    const isNew = page === null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const payload = Object.fromEntries(form.entries());

        setSaving(true);

        if (isNew) {
            router.post('/portal/cms/pages', payload, {
                onSuccess: () => toast.success('გვერდი შეიქმნა.'),
                onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
                onFinish: () => setSaving(false),
            });
        } else {
            router.put(`/portal/cms/pages/${page.id}`, payload, {
                onSuccess: () => toast.success('ცვლილება შენახულია.'),
                onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
                onFinish: () => setSaving(false),
            });
        }
    };

    const publish = () => {
        if (!page) return;
        router.post(
            `/portal/cms/pages/${page.id}/publish`,
            {},
            {
                onSuccess: () => toast.success('გვერდი გამოქვეყნდა.'),
                onError: () => toast.error('ვერ გამოქვეყნდა.'),
            },
        );
    };

    const unpublish = () => {
        if (!page) return;
        router.post(
            `/portal/cms/pages/${page.id}/unpublish`,
            {},
            {
                onSuccess: () => toast.success('გვერდი მოხსნილია გამოქვეყნებიდან.'),
                onError: () => toast.error('ვერ შესრულდა.'),
            },
        );
    };

    const restoreRevision = (revisionId: number) => {
        if (!page) return;
        if (!confirm('აღდგეს ეს ვერსია? მიმდინარე შემცველობა ახალ ვერსიად შეინახება.')) {
            return;
        }
        router.post(
            `/portal/cms/pages/${page.id}/revisions/${revisionId}/restore`,
            {},
            {
                onSuccess: () => toast.success('ვერსია აღდგენილია.'),
                onError: () => toast.error('ვერ აღდგა.'),
            },
        );
    };

    const copyPath = (item: MediaItem) => {
        navigator.clipboard?.writeText(item.url).then(
            () => toast.success('URL დაკოპირებულია — ჩასვით ბლოკებში.'),
            () => toast.error('ვერ დაკოპირდა.'),
        );
    };

    return (
        <PortalLayout>
            <Head title={isNew ? 'ახალი გვერდი' : `რედაქტირება — ${page.title}`} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">
                    {isNew ? 'ახალი გვერდი' : page.title}
                </h1>
                {!isNew && (
                    <div className="flex flex-wrap gap-2">
                        <a
                            href={`/portal/cms/pages/${page.id}/preview`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Button type="button" variant="outline">
                                <Eye size={16} /> გადახედვა
                            </Button>
                        </a>
                        {canPublish && page.status === 'draft' && (
                            <Button type="button" onClick={publish}>
                                <Send size={16} /> გამოქვეყნება
                            </Button>
                        )}
                        {canPublish && page.status === 'published' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={unpublish}
                            >
                                <Undo2 size={16} /> გამოქვეყნების მოხსნა
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {!isNew && (
                <p className="mb-6 text-sm text-slate-500">
                    სტატუსი:{' '}
                    <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-600">
                        {page.status === 'published'
                            ? 'გამოქვეყნებული'
                            : 'მონახაზი'}
                    </span>
                    {page.publishedAt &&
                        ` · გამოქვეყნდა ${new Date(page.publishedAt).toLocaleString('ka-GE')}`}
                </p>
            )}

            <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                htmlFor="slug"
                                className="mb-1 block text-sm font-medium"
                            >
                                Slug
                            </label>
                            <Input
                                id="slug"
                                name="slug"
                                required
                                defaultValue={page?.slug ?? ''}
                                pattern="[a-z0-9-]+"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="locale"
                                className="mb-1 block text-sm font-medium"
                            >
                                ენა (locale)
                            </label>
                            <Input
                                id="locale"
                                name="locale"
                                required
                                defaultValue={page?.locale ?? 'ka'}
                            />
                        </div>
                    </div>

                    <div>
                        <label
                            htmlFor="title"
                            className="mb-1 block text-sm font-medium"
                        >
                            სათაური
                        </label>
                        <Input
                            id="title"
                            name="title"
                            required
                            defaultValue={page?.title ?? ''}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="excerpt"
                            className="mb-1 block text-sm font-medium"
                        >
                            მოკლე აღწერა
                        </label>
                        <Input
                            id="excerpt"
                            name="excerpt"
                            maxLength={255}
                            defaultValue={page?.excerpt ?? ''}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="blocks"
                            className="mb-1 block text-sm font-medium"
                        >
                            შემცველობის ბლოკები (JSON)
                        </label>
                        <textarea
                            id="blocks"
                            name="blocks"
                            required
                            value={blocksValue}
                            onChange={(event) =>
                                setBlocksValue(event.target.value)
                            }
                            rows={14}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                            aria-describedby="blocks-help"
                        />
                        <p
                            id="blocks-help"
                            className="mt-1 text-xs text-slate-500"
                        >
                            ტიპიზებული ბლოკების მასივი (თითოეულს აქვს{' '}
                            <code>type</code>) — ისე, როგორც საჯარო გვერდზე
                            გამოისახება. თავისუფალი HTML/JavaScript დაშვებული
                            არ არის.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                htmlFor="seo_title"
                                className="mb-1 block text-sm font-medium"
                            >
                                SEO სათაური
                            </label>
                            <Input
                                id="seo_title"
                                name="seo_title"
                                maxLength={255}
                                defaultValue={page?.seoTitle ?? ''}
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="seo_description"
                                className="mb-1 block text-sm font-medium"
                            >
                                SEO აღწერა
                            </label>
                            <Input
                                id="seo_description"
                                name="seo_description"
                                maxLength={255}
                                defaultValue={page?.seoDescription ?? ''}
                            />
                        </div>
                    </div>

                    <Button type="submit" disabled={saving}>
                        {isNew ? 'გვერდის შექმნა' : 'ცვლილების შენახვა'}
                    </Button>
                </form>

                <div className="space-y-6">
                    <section className="rounded-xl border border-slate-200 bg-white p-4">
                        <h2 className="mb-3 text-sm font-semibold">
                            მედიაბიბლიოთეკა
                        </h2>
                        {media.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                ჯერ არცერთი ფაილი არ არის ატვირთული.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {media.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center justify-between gap-2 rounded-lg bg-slate-50 p-2 text-xs"
                                    >
                                        <span className="truncate">
                                            {item.originalFilename}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => copyPath(item)}
                                            className="inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 font-medium hover:bg-slate-100"
                                        >
                                            <Copy size={12} /> URL
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <a
                            href="/portal/cms/media"
                            className="mt-3 inline-block text-xs font-medium text-slate-600 underline"
                        >
                            მედიის მართვა →
                        </a>
                    </section>

                    {!isNew && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                                <History size={16} /> ვერსიების ისტორია
                            </h2>
                            {revisions.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    ჯერ არცერთი ვერსია არ არის შენახული.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {revisions.map((revision) => (
                                        <li
                                            key={revision.id}
                                            className="rounded-lg bg-slate-50 p-2 text-xs"
                                        >
                                            <p className="font-medium">
                                                {revision.title}
                                            </p>
                                            <p className="text-slate-500">
                                                {revision.authorName ?? '—'} ·{' '}
                                                {revision.createdAt
                                                    ? new Date(
                                                          revision.createdAt,
                                                      ).toLocaleString('ka-GE')
                                                    : ''}
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    restoreRevision(
                                                        revision.id,
                                                    )
                                                }
                                                className="mt-1 font-medium text-slate-700 underline"
                                            >
                                                აღდგენა
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </PortalLayout>
    );
}
