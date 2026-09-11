import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Copy, Eye, History, Send, Undo2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type PostDetail = {
    id: number;
    slug: string;
    locale: string;
    title: string;
    excerpt: string | null;
    body: string;
    coverImagePath: string | null;
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
    post: PostDetail | null;
    revisions: Revision[];
    media: MediaItem[];
    canPublish: boolean;
};

export default function CmsPostEdit({ post, revisions, media, canPublish }: Props) {
    const [saving, setSaving] = useState(false);
    const [coverImagePath, setCoverImagePath] = useState(
        post?.coverImagePath ?? '',
    );

    const isNew = post === null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const payload = Object.fromEntries(form.entries());

        setSaving(true);

        if (isNew) {
            router.post('/portal/cms/posts', payload, {
                onSuccess: () => toast.success('ამბავი შეიქმნა.'),
                onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
                onFinish: () => setSaving(false),
            });
        } else {
            router.put(`/portal/cms/posts/${post.id}`, payload, {
                onSuccess: () => toast.success('ცვლილება შენახულია.'),
                onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
                onFinish: () => setSaving(false),
            });
        }
    };

    const publish = () => {
        if (!post) return;
        router.post(
            `/portal/cms/posts/${post.id}/publish`,
            {},
            {
                onSuccess: () => toast.success('ამბავი გამოქვეყნდა.'),
                onError: () => toast.error('ვერ გამოქვეყნდა.'),
            },
        );
    };

    const unpublish = () => {
        if (!post) return;
        router.post(
            `/portal/cms/posts/${post.id}/unpublish`,
            {},
            {
                onSuccess: () =>
                    toast.success('ამბავი მოხსნილია გამოქვეყნებიდან.'),
                onError: () => toast.error('ვერ შესრულდა.'),
            },
        );
    };

    const restoreRevision = (revisionId: number) => {
        if (!post) return;
        if (
            !confirm(
                'აღდგეს ეს ვერსია? მიმდინარე შემცველობა ახალ ვერსიად შეინახება.',
            )
        ) {
            return;
        }
        router.post(
            `/portal/cms/posts/${post.id}/revisions/${revisionId}/restore`,
            {},
            {
                onSuccess: () => toast.success('ვერსია აღდგენილია.'),
                onError: () => toast.error('ვერ აღდგა.'),
            },
        );
    };

    const useCover = (item: MediaItem) => {
        setCoverImagePath(item.path);
        toast.success('ყდის სურათი არჩეულია — შეინახეთ ფორმა.');
    };

    return (
        <PortalLayout>
            <Head title={isNew ? 'ახალი ამბავი' : `რედაქტირება — ${post.title}`} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">
                    {isNew ? 'ახალი ამბავი' : post.title}
                </h1>
                {!isNew && (
                    <div className="flex flex-wrap gap-2">
                        <a
                            href={`/portal/cms/posts/${post.id}/preview`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Button type="button" variant="outline">
                                <Eye size={16} /> გადახედვა
                            </Button>
                        </a>
                        {canPublish && post.status === 'draft' && (
                            <Button type="button" onClick={publish}>
                                <Send size={16} /> გამოქვეყნება
                            </Button>
                        )}
                        {canPublish && post.status === 'published' && (
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
                        {post.status === 'published'
                            ? 'გამოქვეყნებული'
                            : 'მონახაზი'}
                    </span>
                    {post.publishedAt &&
                        ` · გამოქვეყნდა ${new Date(post.publishedAt).toLocaleString('ka-GE')}`}
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
                                defaultValue={post?.slug ?? ''}
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
                                defaultValue={post?.locale ?? 'ka'}
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
                            defaultValue={post?.title ?? ''}
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
                            defaultValue={post?.excerpt ?? ''}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="body"
                            className="mb-1 block text-sm font-medium"
                        >
                            ტექსტი
                        </label>
                        <textarea
                            id="body"
                            name="body"
                            required
                            defaultValue={post?.body ?? ''}
                            rows={12}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="cover_image_path"
                            className="mb-1 block text-sm font-medium"
                        >
                            ყდის სურათის path (აირჩიეთ მედიადან)
                        </label>
                        <Input
                            id="cover_image_path"
                            name="cover_image_path"
                            value={coverImagePath}
                            onChange={(event) =>
                                setCoverImagePath(event.target.value)
                            }
                        />
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
                                defaultValue={post?.seoTitle ?? ''}
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
                                defaultValue={post?.seoDescription ?? ''}
                            />
                        </div>
                    </div>

                    <Button type="submit" disabled={saving}>
                        {isNew ? 'ამბის შექმნა' : 'ცვლილების შენახვა'}
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
                                            onClick={() => useCover(item)}
                                            className="inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 font-medium hover:bg-slate-100"
                                        >
                                            <Copy size={12} /> ყდად
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
