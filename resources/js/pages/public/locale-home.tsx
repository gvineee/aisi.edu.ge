import { Head, Link, usePage } from '@inertiajs/react';
import PublicLayout from '@/layouts/public/public-layout';
import { usePublicLocale } from '@/lib/public-i18n';
import type { Brand } from '@/types';

type Props = {
    locale: string;
    pages: { url: string; title: string; excerpt: string | null }[];
    posts: {
        url: string;
        title: string;
        excerpt: string | null;
        publishedAt: string | null;
    }[];
};

/**
 * Start page of a language the school has only partly translated: what is
 * available in it, and a way to the full Georgian site.
 */
export default function LocaleHome({ locale, pages, posts }: Props) {
    const { brand } = usePage<{ brand: Brand | null }>().props;
    const { t, dateLocale } = usePublicLocale();

    return (
        <PublicLayout>
            <Head title={brand?.name ?? t('home')} />
            <section
                lang={locale}
                className="mx-auto max-w-4xl px-6 py-16 sm:py-24"
            >
                <h1 className="mb-4 text-3xl sm:text-4xl">{brand?.name}</h1>
                <p className="mb-10 text-slate-600">{t('localeHomeIntro')}</p>

                <h2 className="mb-4 text-xl">{t('localeHomeTitle')}</h2>
                <ul className="mb-12 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {pages.map((page) => (
                        <li key={page.url}>
                            <Link
                                href={page.url}
                                className="block h-full rounded-xl border border-slate-200 p-5 transition hover:border-[var(--brand-accent)]"
                            >
                                <span className="text-lg font-medium">
                                    {page.title}
                                </span>
                                {page.excerpt && (
                                    <span className="mt-2 block text-sm text-slate-600">
                                        {page.excerpt}
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>

                {posts.length > 0 && (
                    <>
                        <h2 className="mb-4 text-xl">{t('newsTitle')}</h2>
                        <ul className="mb-12 space-y-4">
                            {posts.map((post) => (
                                <li
                                    key={post.url}
                                    className="border-b border-slate-200 pb-4"
                                >
                                    {post.publishedAt && (
                                        <time className="text-xs text-slate-500">
                                            {new Date(
                                                post.publishedAt,
                                            ).toLocaleDateString(dateLocale)}
                                        </time>
                                    )}
                                    <Link
                                        href={post.url}
                                        className="mt-1 block font-medium hover:underline"
                                    >
                                        {post.title}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                <a
                    href="/"
                    lang="ka"
                    className="inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                >
                    {t('inOriginal')} →
                </a>
            </section>
        </PublicLayout>
    );
}
