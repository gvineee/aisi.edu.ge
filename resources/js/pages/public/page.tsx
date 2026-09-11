import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    BookOpen,
    Sparkles,
    Users,
} from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { useOpenVisitDialog } from '@/components/public/visit-dialog-context';
import type { Brand } from '@/types';

type Block =
    | {
          type: 'hero';
          eyebrow?: string;
          heading: string;
          headingLine2?: string;
          headingEmphasis?: string;
          body?: string;
          note?: string;
          imageAlt?: string;
          imageCaption?: string;
      }
    | {
          type: 'values';
          items: Array<{ label: string }>;
      }
    | {
          type: 'programs';
          heading: string;
          items: Array<{ title: string; grade: string; body: string }>;
      }
    | {
          type: 'history';
          eyebrow?: string;
          heading: string;
          lead?: string;
          body: string;
          ctaLabel?: string;
          ctaHref?: string;
      }
    | {
          type: 'portal_promo';
          eyebrow?: string;
          heading: string;
          body?: string;
          ctaLabel?: string;
          ctaHref?: string;
      }
    | {
          type: 'life';
          eyebrow?: string;
          heading: string;
          ctaLabel?: string;
          ctaHref?: string;
      }
    | { type: 'text'; heading: string; body: string }
    | { type: 'contact_cta'; eyebrow?: string; heading: string; body?: string };

type LatestPost = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
};

type Props = {
    page: {
        slug: string;
        title: string;
        excerpt: string | null;
        blocks: Block[];
        seoTitle: string | null;
        seoDescription: string | null;
    };
    latestPosts?: LatestPost[];
    /**
     * Set only by CmsPageController::preview — lets an editor see a
     * draft/unpublished page through the exact same rendering path the
     * public site uses, instead of a second implementation that could
     * drift from it. Never set for a real public visitor.
     */
    preview?: boolean;
};

/**
 * Renders any tenant CMS page — home included — from its typed blocks.
 * One rendering path for every page keeps the block set (and this
 * component) the single thing to maintain, instead of one file per page.
 */
export default function CmsPage({ page, latestPosts = [], preview = false }: Props) {
    const { brand } = usePage<{ brand: Brand | null }>().props;
    const accent = brand?.colors.accent ?? '#F5683C';
    const primary = brand?.colors.primary ?? '#132B45';
    const openVisitDialog = useOpenVisitDialog();

    return (
        <PublicLayout>
            <Head title={preview ? `[წინასწარი ნახვა] ${page.seoTitle ?? page.title}` : (page.seoTitle ?? page.title)}>
                {page.seoDescription && (
                    <meta name="description" content={page.seoDescription} />
                )}
                {preview && <meta name="robots" content="noindex" />}
            </Head>

            {preview && (
                <div
                    role="status"
                    className="sticky top-0 z-50 bg-amber-400 px-4 py-2 text-center text-sm font-semibold text-amber-950"
                >
                    წინასწარი ნახვა — ეს გვერდი ჯერ არ არის საჯაროდ
                    გამოქვეყნებული.
                </div>
            )}

            {page.blocks.map((block, index) => {
                switch (block.type) {
                    case 'hero':
                        return (
                            <section
                                key={index}
                                className="mx-auto max-w-6xl px-6 py-16 sm:py-24"
                            >
                                <div className="grid gap-12 lg:grid-cols-2 lg:items-center">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="mb-6 text-xs font-bold tracking-widest text-slate-500">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h1 className="text-4xl leading-tight tracking-tight text-balance sm:text-5xl">
                                            {block.heading}
                                            {block.headingLine2 && (
                                                <>
                                                    <br />
                                                    {block.headingLine2}{' '}
                                                    {block.headingEmphasis && (
                                                        <em
                                                            className="not-italic"
                                                            style={{
                                                                color: accent,
                                                            }}
                                                        >
                                                            {
                                                                block.headingEmphasis
                                                            }
                                                        </em>
                                                    )}
                                                </>
                                            )}
                                        </h1>
                                        {block.body && (
                                            <p className="mt-6 max-w-md text-lg text-slate-600">
                                                {block.body}
                                            </p>
                                        )}
                                        <div className="mt-8 flex flex-wrap items-center gap-6">
                                            <button
                                                type="button"
                                                onClick={openVisitDialog}
                                                className="inline-flex items-center gap-2 rounded-lg px-6 py-3 font-semibold text-white"
                                                style={{
                                                    backgroundColor: primary,
                                                }}
                                            >
                                                მოდი, გაიცანი{' '}
                                                {brand?.name ?? ''}{' '}
                                                <ArrowUpRight size={18} />
                                            </button>
                                            <a
                                                href="#about"
                                                className="inline-flex items-center gap-1 font-semibold"
                                                style={{ color: accent }}
                                            >
                                                ჩვენი ხედვა{' '}
                                                <ArrowRight size={16} />
                                            </a>
                                        </div>
                                        {block.note && (
                                            <div className="mt-8 flex items-start gap-3 text-sm text-slate-600">
                                                <Sparkles
                                                    className="mt-0.5 h-5 w-5 shrink-0"
                                                    style={{ color: accent }}
                                                />
                                                <span>{block.note}</span>
                                            </div>
                                        )}
                                    </div>
                                    <div className="relative">
                                        {brand?.heroImageUrl ? (
                                            <div className="overflow-hidden rounded-[130px_14px_14px_14px]">
                                                <img
                                                    src={brand.heroImageUrl}
                                                    alt={block.imageAlt ?? ''}
                                                    className="h-72 w-full object-cover sm:h-96"
                                                />
                                                {block.imageCaption && (
                                                    <p className="absolute top-3 right-3 rounded-full bg-white/90 px-3 py-1 text-xs text-slate-600">
                                                        {block.imageCaption}
                                                    </p>
                                                )}
                                            </div>
                                        ) : (
                                            <div
                                                className="flex h-72 items-center justify-center rounded-[130px_14px_14px_14px] bg-slate-100 sm:h-96"
                                                aria-hidden="true"
                                            >
                                                <BookOpen
                                                    className="h-16 w-16"
                                                    style={{ color: accent }}
                                                />
                                            </div>
                                        )}
                                        <div
                                            className="absolute top-7 -right-2 hidden text-[10px] tracking-[0.3em] text-slate-400 sm:block"
                                            style={{
                                                writingMode: 'vertical-rl',
                                            }}
                                            aria-hidden="true"
                                        >
                                            LEARN. DISCOVER. BECOME.
                                        </div>
                                        <div className="absolute -bottom-6 left-4 flex max-w-56 items-center gap-3 rounded-xl bg-white p-4 shadow-lg ring-1 ring-slate-200">
                                            <span
                                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg"
                                                style={{
                                                    backgroundColor: `${accent}1a`,
                                                    color: accent,
                                                }}
                                            >
                                                <BookOpen size={22} />
                                            </span>
                                            <div className="text-sm">
                                                <p className="font-semibold">
                                                    მეტი, ვიდრე გაკვეთილი
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    საკუთარი თავის აღმოჩენის
                                                    ადგილი
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        );

                    case 'values': {
                        const valueIcons = [BookOpen, Users, Sparkles];

                        return (
                            <div
                                key={index}
                                className="border-y border-slate-100 bg-slate-50 py-6"
                            >
                                <div className="mx-auto flex max-w-6xl flex-col gap-4 px-6 text-sm font-medium text-slate-600 sm:flex-row sm:flex-wrap sm:justify-center sm:gap-x-10">
                                    {block.items.map((item, itemIndex) => {
                                        const Icon =
                                            valueIcons[
                                                itemIndex % valueIcons.length
                                            ];

                                        return (
                                            <span
                                                key={item.label}
                                                className="inline-flex items-center gap-2"
                                            >
                                                <Icon
                                                    size={18}
                                                    style={{ color: accent }}
                                                />
                                                {item.label}
                                            </span>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    }

                    case 'history':
                        return (
                            <section
                                key={index}
                                id="about"
                                className="border-t border-slate-100 bg-slate-50 py-16 sm:py-24"
                            >
                                <div className="mx-auto grid max-w-6xl gap-10 px-6 lg:grid-cols-2 lg:items-start">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="mb-4 text-xs font-bold tracking-widest text-slate-500">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h2 className="text-2xl sm:text-3xl">
                                            {block.heading}
                                        </h2>
                                    </div>
                                    <div>
                                        {block.lead && (
                                            <p className="text-lg font-medium text-slate-700">
                                                {block.lead}
                                            </p>
                                        )}
                                        <p className="mt-4 text-slate-600">
                                            {block.body}
                                        </p>
                                        {block.ctaHref && (
                                            <Link
                                                href={block.ctaHref}
                                                className="mt-6 inline-flex items-center gap-1 font-semibold"
                                                style={{ color: accent }}
                                            >
                                                {block.ctaLabel ?? 'გაიგე მეტი'}{' '}
                                                <ArrowUpRight size={16} />
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            </section>
                        );

                    case 'programs': {
                        const programAccents = [
                            { bg: '#FFF1EA', fg: '#C84925' },
                            { bg: '#E9F0F5', fg: primary },
                            { bg: '#EAF5EF', fg: '#247452' },
                        ];

                        return (
                            <section
                                key={index}
                                className="bg-slate-50 py-16 sm:py-24"
                            >
                                <div className="mx-auto max-w-6xl px-6">
                                    <div className="mb-10 flex flex-wrap items-end justify-between gap-4">
                                        <h2 className="text-2xl sm:text-3xl">
                                            {block.heading}
                                        </h2>
                                        <span className="text-xs font-semibold text-slate-400">
                                            01 —{' '}
                                            {String(
                                                block.items.length,
                                            ).padStart(2, '0')}
                                        </span>
                                    </div>
                                    <div className="grid gap-6 sm:grid-cols-3">
                                        {block.items.map((item, itemIndex) => {
                                            const tone =
                                                programAccents[
                                                    itemIndex %
                                                        programAccents.length
                                                ];

                                            return (
                                                <div
                                                    key={item.title}
                                                    className="rounded-xl bg-white p-7 shadow-sm ring-1 ring-slate-200"
                                                >
                                                    <div className="mb-4 flex items-center justify-between">
                                                        <span
                                                            className="flex h-9 w-9 items-center justify-center rounded-lg text-sm font-bold"
                                                            style={{
                                                                backgroundColor:
                                                                    tone.bg,
                                                                color: tone.fg,
                                                            }}
                                                        >
                                                            {String(
                                                                itemIndex + 1,
                                                            ).padStart(2, '0')}
                                                        </span>
                                                    </div>
                                                    <p className="mb-3 text-xs font-semibold text-slate-500">
                                                        {item.grade}
                                                    </p>
                                                    <h3 className="mb-3 text-lg">
                                                        {item.title}
                                                    </h3>
                                                    <p className="text-sm text-slate-600">
                                                        {item.body}
                                                    </p>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </section>
                        );
                    }

                    case 'portal_promo':
                        return (
                            <section
                                key={index}
                                className="py-16 text-white sm:py-24"
                                style={{ backgroundColor: primary }}
                            >
                                <div className="mx-auto grid max-w-6xl gap-10 px-6 lg:grid-cols-2 lg:items-center">
                                    <div>
                                        {block.eyebrow && (
                                            <p
                                                className="mb-4 text-xs font-bold tracking-widest"
                                                style={{ color: accent }}
                                            >
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h2 className="text-2xl text-white sm:text-3xl">
                                            {block.heading}
                                        </h2>
                                        {block.body && (
                                            <p className="mt-4 text-white/80">
                                                {block.body}
                                            </p>
                                        )}
                                        <Link
                                            href={block.ctaHref ?? '/login'}
                                            className="mt-6 inline-flex items-center gap-2 rounded-lg bg-white px-6 py-3 font-semibold"
                                            style={{ color: primary }}
                                        >
                                            {block.ctaLabel ?? 'შედი პორტალში'}{' '}
                                            <ArrowUpRight size={18} />
                                        </Link>
                                    </div>
                                    <div
                                        className="rounded-2xl bg-white/10 p-6 ring-1 ring-white/20"
                                        aria-hidden="true"
                                    >
                                        <div className="flex items-center gap-2 rounded-lg bg-white/10 p-4">
                                            <Sparkles
                                                size={20}
                                                style={{ color: accent }}
                                            />
                                            <div>
                                                <p className="font-semibold text-white">
                                                    ჩემი {brand?.name ?? ''}
                                                </p>
                                                <p className="text-sm text-white/70">
                                                    განრიგი, დასწრება და
                                                    სიახლეები ერთ სივრცეში
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        );

                    case 'text':
                        return (
                            <section
                                key={index}
                                className="mx-auto max-w-3xl px-6 py-16"
                            >
                                <h2 className="mb-6 text-2xl sm:text-3xl">
                                    {block.heading}
                                </h2>
                                <p className="text-lg leading-relaxed text-slate-600">
                                    {block.body}
                                </p>
                            </section>
                        );

                    case 'life':
                        return (
                            <section
                                key={index}
                                id="life"
                                className="mx-auto max-w-6xl px-6 py-16"
                            >
                                <div className="mb-10 flex flex-wrap items-end justify-between gap-4">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="mb-2 text-xs font-bold tracking-widest text-slate-500">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h2 className="text-2xl sm:text-3xl">
                                            {block.heading}
                                        </h2>
                                    </div>
                                    {latestPosts.length > 0 && (
                                        <Link
                                            href={block.ctaHref ?? '/news'}
                                            className="inline-flex items-center gap-1 font-semibold"
                                            style={{ color: accent }}
                                        >
                                            {block.ctaLabel ?? 'ყველა ამბავი'}{' '}
                                            <ArrowUpRight size={16} />
                                        </Link>
                                    )}
                                </div>

                                {latestPosts.length === 0 ? (
                                    <p className="rounded-xl bg-slate-50 p-8 text-center text-slate-500">
                                        სიახლეები მალე გამოქვეყნდება.
                                    </p>
                                ) : (
                                    <div className="grid gap-6 sm:grid-cols-3">
                                        {latestPosts.map((post) => (
                                            <Link
                                                key={post.slug}
                                                href={`/news/${post.slug}`}
                                                className="block rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200"
                                            >
                                                {post.publishedAt && (
                                                    <p className="mb-2 text-xs font-semibold text-slate-500">
                                                        {new Date(
                                                            post.publishedAt,
                                                        ).toLocaleDateString(
                                                            'ka-GE',
                                                        )}
                                                    </p>
                                                )}
                                                <h3 className="mb-2 text-lg">
                                                    {post.title}
                                                </h3>
                                                {post.excerpt && (
                                                    <p className="text-sm text-slate-600">
                                                        {post.excerpt}
                                                    </p>
                                                )}
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </section>
                        );

                    case 'contact_cta':
                        return (
                            <section
                                key={index}
                                id="contact"
                                className="border-t border-slate-200 bg-[var(--brand-secondary,#F4F7FA)] py-16"
                            >
                                <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 px-6 sm:flex-row sm:items-center">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="mb-2 text-xs font-bold tracking-widest text-slate-500">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h2 className="text-2xl sm:text-3xl">
                                            {block.heading}
                                        </h2>
                                        {block.body && (
                                            <p className="mt-3 text-slate-600">
                                                {block.body}
                                            </p>
                                        )}
                                        {brand?.contact.phone && (
                                            <p className="mt-4 text-sm text-slate-600">
                                                {brand.contact.phone}
                                                {brand.contact.email &&
                                                    ` · ${brand.contact.email}`}
                                            </p>
                                        )}
                                        {brand?.contact.address && (
                                            <p className="mt-1 text-sm text-slate-600">
                                                {brand.contact.address}
                                            </p>
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={openVisitDialog}
                                        className="inline-flex items-center gap-2 rounded-lg px-6 py-3 font-semibold text-white"
                                        style={{
                                            backgroundColor: primary,
                                        }}
                                    >
                                        დაგეგმე ვიზიტი{' '}
                                        <ArrowUpRight size={18} />
                                    </button>
                                </div>
                            </section>
                        );

                    default:
                        return null;
                }
            })}
        </PublicLayout>
    );
}
