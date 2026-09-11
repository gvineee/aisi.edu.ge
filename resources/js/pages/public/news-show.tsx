import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';

type Props = {
    post: {
        title: string;
        excerpt: string | null;
        body: string;
        publishedAt: string | null;
        seoTitle: string | null;
        seoDescription: string | null;
    };
    /** Set only by CmsPostController::preview — see public/page.tsx's Props. */
    preview?: boolean;
};

export default function NewsShow({ post, preview = false }: Props) {
    return (
        <PublicLayout>
            <Head title={preview ? `[წინასწარი ნახვა] ${post.seoTitle ?? post.title}` : (post.seoTitle ?? post.title)}>
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
                    href="/news"
                    className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-slate-700"
                >
                    <ArrowLeft size={16} /> აისის ამბებში დაბრუნება
                </Link>

                {post.publishedAt && (
                    <time className="block text-xs text-slate-500">
                        {new Date(post.publishedAt).toLocaleDateString('ka-GE')}
                    </time>
                )}
                <h1 className="mt-3 mb-6 text-3xl sm:text-4xl">{post.title}</h1>
                <p className="text-lg leading-relaxed whitespace-pre-line text-slate-700">
                    {post.body}
                </p>
            </article>
        </PublicLayout>
    );
}
