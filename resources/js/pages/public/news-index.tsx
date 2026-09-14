import { Head, Link } from '@inertiajs/react';
import PublicLayout from '@/layouts/public/public-layout';

type PostSummary = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
    coverImageUrl: string | null;
};

type Paginated<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    current_page: number;
    last_page: number;
};

type Props = {
    posts: Paginated<PostSummary>;
};

export default function NewsIndex({ posts }: Props) {
    return (
        <PublicLayout>
            <Head title="აისის ამბები" />

            <section className="mx-auto max-w-4xl px-6 py-16 sm:py-24">
                <p className="mb-4 text-xs font-bold tracking-widest text-slate-500">
                    სასკოლო ცხოვრება
                </p>
                <h1 className="mb-12 text-3xl sm:text-4xl">აისის ამბები</h1>

                {posts.data.length === 0 && (
                    <p className="text-slate-600">
                        ამ ეტაპზე გამოქვეყნებული ამბავი არ არის.
                    </p>
                )}

                <div className="space-y-8">
                    {posts.data.map((post) => (
                        <article
                            key={post.slug}
                            className="border-b border-slate-200 pb-8"
                        >
                            {post.coverImageUrl && (
                                <img
                                    src={post.coverImageUrl}
                                    alt=""
                                    className="mb-4 h-48 w-full rounded-lg object-cover"
                                />
                            )}
                            {post.publishedAt && (
                                <time className="text-xs text-slate-500">
                                    {new Date(
                                        post.publishedAt,
                                    ).toLocaleDateString('ka-GE')}
                                </time>
                            )}
                            <h2 className="mt-2 mb-2 text-xl">
                                <Link
                                    href={`/news/${post.slug}`}
                                    className="hover:underline"
                                >
                                    {post.title}
                                </Link>
                            </h2>
                            {post.excerpt && (
                                <p className="text-slate-600">{post.excerpt}</p>
                            )}
                        </article>
                    ))}
                </div>

                {(posts.prev_page_url || posts.next_page_url) && (
                    <nav
                        className="mt-10 flex justify-between text-sm font-medium"
                        aria-label="გვერდები"
                    >
                        {posts.prev_page_url ? (
                            <Link href={posts.prev_page_url}>
                                ← წინა გვერდი
                            </Link>
                        ) : (
                            <span />
                        )}
                        {posts.next_page_url && (
                            <Link href={posts.next_page_url}>
                                შემდეგი გვერდი →
                            </Link>
                        )}
                    </nav>
                )}
            </section>
        </PublicLayout>
    );
}
