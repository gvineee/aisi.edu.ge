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
};

export default function NewsShow({ post }: Props) {
    return (
        <PublicLayout>
            <Head title={post.seoTitle ?? post.title}>
                {post.seoDescription && (
                    <meta name="description" content={post.seoDescription} />
                )}
            </Head>

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
