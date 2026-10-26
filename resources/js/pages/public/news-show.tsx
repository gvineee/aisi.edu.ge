import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { usePublicLocale } from '@/lib/public-i18n';
import ContentMediaBlock, {
    type ContentMedia,
} from '@/components/public/content-media';

type Props = {
    post: {
        title: string;
        excerpt: string | null;
        body: string;
        publishedAt: string | null;
        seoTitle: string | null;
        seoDescription: string | null;
        coverImageUrl: string | null;
    };
    /** The section this post is placed in when it is not plain news. */
    breadcrumb?: { name: string; href: string } | null;
    /** Images and attachments carried over from the original post. */
    media?: ContentMedia;
    /** Set only by CmsPostController::preview — see public/page.tsx's Props. */
    preview?: boolean;
};

export default function NewsShow({
    post,
    breadcrumb = null,
    media,
    preview = false,
}: Props) {
    const { t, isDefault, dateLocale, path } = usePublicLocale();

    return (
        <PublicLayout>
            <Head
                title={
                    preview
                        ? `[წინასწარი ნახვა] ${post.seoTitle ?? post.title}`
                        : (post.seoTitle ?? post.title)
                }
            >
                {post.seoDescription && (
                    <meta name="description" content={post.seoDescription} />
                )}
                {preview && <meta name="robots" content="noindex" />}
            </Head>

            {preview && (
                <div
                    role="status"
                    className="sticky top-0 z-50 bg-amber-400 px-4 py-2 text-center text-sm font-semibold text-amber-950"
                >
                    წინასწარი ნახვა — ეს ამბავი ჯერ არ არის საჯაროდ
                    გამოქვეყნებული.
                </div>
            )}

            <article className="mx-auto max-w-3xl px-6 py-16 sm:py-24">
                <Link
                    href={breadcrumb?.href ?? path('/news')}
                    className="mb-8 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-slate-500 hover:text-slate-700"
                >
                    <ArrowLeft size={16} />{' '}
                    {breadcrumb
                        ? `${breadcrumb.name} — დაბრუნება`
                        : isDefault
                          ? 'აისის ამბებში დაბრუნება'
                          : t('backToNews')}
                </Link>

                {post.publishedAt && (
                    <time className="block text-xs text-slate-500">
                        {new Date(post.publishedAt).toLocaleDateString(
                            dateLocale,
                        )}
                    </time>
                )}
                <h1 className="mt-3 mb-6 text-3xl sm:text-4xl">{post.title}</h1>
                {post.coverImageUrl && (
                    <img
                        src={post.coverImageUrl}
                        alt=""
                        className="mb-8 w-full rounded-xl object-cover"
                    />
                )}
                <p className="text-lg leading-relaxed whitespace-pre-line text-slate-700">
                    {post.body}
                </p>
            </article>
            {media && <ContentMediaBlock media={media} />}
        </PublicLayout>
    );
}
