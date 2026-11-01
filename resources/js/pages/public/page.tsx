import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    BookOpen,
    FileText,
    Sparkles,
    Users,
} from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import ContentMediaBlock, {
    type ContentMedia,
} from '@/components/public/content-media';
import {
    CategoryBreadcrumbNav,
    CategorySection,
    type CategoryBreadcrumb,
    type CategorySectionData,
} from '@/components/public/category-section';
import Logo from '@/components/public/logo';
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
          items: Array<{
              title: string;
              grade: string;
              body: string;
              href?: string;
          }>;
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
    | {
          type: 'teachers';
          eyebrow?: string;
          heading: string;
          ctaLabel?: string;
          ctaHref?: string;
      }
    | { type: 'text'; heading: string; body: string | string[] }
    | { type: 'contact_cta'; eyebrow?: string; heading: string; body?: string }
    | {
          type: 'documents';
          heading?: string;
          groups: Array<{
              heading: string;
              items: Array<{ title: string; url: string }>;
          }>;
      };

type LatestPost = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
    coverImageUrl: string | null;
};

type FeaturedTeacher = {
    slug: string;
    name: string;
    subject: string;
    photoUrl: string | null;
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
    featuredTeachers?: FeaturedTeacher[];
    categorySection?: CategorySectionData | null;
    breadcrumb?: CategoryBreadcrumb | null;
    media?: ContentMedia;
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
export default function CmsPage(props: Props) {
    // The visit dialog's provider lives inside PublicLayout, so everything
    // that opens it must render below the layout, never beside it.
    return (
        <PublicLayout>
            <CmsPageContent {...props} />
        </PublicLayout>
    );
}

function CmsPageContent({
    page,
    latestPosts = [],
    featuredTeachers = [],
    categorySection = null,
    breadcrumb = null,
    media,
    preview = false,
}: Props) {
    const { brand } = usePage<{ brand: Brand | null }>().props;
    const accent = brand?.colors.accent ?? '#F5683C';
    const primary = brand?.colors.primary ?? '#132B45';
    const openVisitDialog = useOpenVisitDialog();
    // "Our vision" scrolls to the page's own about section when it has one.
    const visionHref = page.blocks.some((block) => block.type === 'history')
        ? '#about'
        : page.slug === 'about'
          ? null
          : '/about';

    return (
        <>
            <Head
                title={
                    preview
                        ? `[წინასწარი ნახვა] ${page.seoTitle ?? page.title}`
                        : (page.seoTitle ?? page.title)
                }
            >
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

            {breadcrumb && <CategoryBreadcrumbNav breadcrumb={breadcrumb} />}

            {/* Every page needs one h1; hero-less CMS pages get the title for assistive tech. */}
            {!page.blocks.some((block) => block.type === 'hero') && (
                <h1 className="sr-only">{page.title}</h1>
            )}

            {page.blocks.map((block, index) => {
                switch (block.type) {
                    case 'hero':
                        return (
                            <section key={index} className="public-wrap">
                                {/* Proportions from design/app/globals.css `.hero` (see app.css). */}
                                <div className="public-hero">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="public-eyebrow">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h1 className="public-hero-title text-balance">
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
                                            <p className="public-hero-lead">
                                                {block.body}
                                            </p>
                                        )}
                                        <div className="mt-8 flex flex-wrap items-center gap-x-[26px] gap-y-4">
                                            <button
                                                type="button"
                                                onClick={openVisitDialog}
                                                className="inline-flex min-h-12 items-center gap-3.5 rounded-lg px-[22px] py-[15px] text-sm font-semibold"
                                                style={{
                                                    backgroundColor: accent,
                                                    color: primary,
                                                }}
                                            >
                                                მოდი, გაიცანი{' '}
                                                {brand?.name ?? ''}{' '}
                                                <ArrowUpRight size={18} />
                                            </button>
                                            {visionHref?.startsWith('#') && (
                                                <a
                                                    href={visionHref}
                                                    className="inline-flex min-h-11 items-center gap-1 font-semibold"
                                                    style={{ color: primary }}
                                                >
                                                    ჩვენი ხედვა{' '}
                                                    <ArrowRight size={16} />
                                                </a>
                                            )}
                                            {visionHref?.startsWith('/') && (
                                                <Link
                                                    href={visionHref}
                                                    className="inline-flex min-h-11 items-center gap-1 font-semibold"
                                                    style={{ color: primary }}
                                                >
                                                    ჩვენი ხედვა{' '}
                                                    <ArrowRight size={16} />
                                                </Link>
                                            )}
                                        </div>
                                        {block.note && (
                                            <div className="mt-8 flex items-start gap-[15px] text-xs leading-[1.8] text-slate-600 sm:mt-[42px]">
                                                <Sparkles
                                                    className="h-6 w-6 shrink-0"
                                                    style={{ color: accent }}
                                                />
                                                <span>{block.note}</span>
                                            </div>
                                        )}
                                    </div>
                                    <div className="public-hero-visual">
                                        {brand?.heroImageUrl ? (
                                            <div className="public-photo-frame">
                                                <img
                                                    src={brand.heroImageUrl}
                                                    alt={block.imageAlt ?? ''}
                                                    className="h-full w-full object-cover"
                                                />
                                            </div>
                                        ) : (
                                            <div
                                                className="public-photo-frame flex items-center justify-center bg-slate-100"
                                                aria-hidden="true"
                                            >
                                                <BookOpen
                                                    className="h-16 w-16"
                                                    style={{ color: accent }}
                                                />
                                            </div>
                                        )}
                                        <div
                                            className="absolute top-7 -right-2 hidden text-[10px] tracking-[0.3em] text-slate-500 sm:block"
                                            style={{
                                                writingMode: 'vertical-rl',
                                            }}
                                            aria-hidden="true"
                                        >
                                            LEARN. DISCOVER. BECOME.
                                        </div>
                                        <div className="public-floating-card">
                                            <span
                                                className="inline-flex shrink-0 rounded-xl p-[13px]"
                                                style={{
                                                    backgroundColor: '#ffede7',
                                                    color: '#c34621',
                                                }}
                                            >
                                                <BookOpen size={27} />
                                            </span>
                                            <div>
                                                <p className="text-sm font-semibold text-[var(--brand-primary)]">
                                                    მეტი, ვიდრე გაკვეთილი
                                                </p>
                                                <p className="mt-1 text-xs text-slate-600">
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
                                <div className="public-wrap flex flex-col gap-[18px] text-[13px] text-[var(--brand-primary)] sm:flex-row sm:flex-wrap sm:justify-between sm:gap-6 sm:text-sm">
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
                                className="border-t border-slate-100 bg-slate-50 py-12 sm:py-[76px]"
                            >
                                <div className="public-wrap grid gap-10 lg:grid-cols-2 lg:items-start">
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
                                                style={{ color: primary }}
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
                        // design/app/globals.css .peach / .blue / .green
                        const programTones = ['#ffede5', '#eaf1f9', '#e5f2ed'];
                        // Each stage opens its own description on the
                        // learning page (or an editor-set link). The learning
                        // page itself is that description, so no link there.
                        const stageHref = (
                            item: (typeof block.items)[number],
                            n: number,
                        ) =>
                            item.href ??
                            (page.slug === 'learning'
                                ? null
                                : `/learning#stage-${n}`);

                        return (
                            <section
                                key={index}
                                className="bg-slate-50 py-12 sm:py-[76px]"
                            >
                                <div className="public-wrap">
                                    <div className="mb-10 flex flex-wrap items-end justify-between gap-4">
                                        <h2 className="text-2xl sm:text-3xl">
                                            {block.heading}
                                        </h2>
                                        <span
                                            className="hidden text-sm whitespace-nowrap text-slate-600 sm:inline"
                                            aria-hidden="true"
                                        >
                                            01 —{' '}
                                            {String(
                                                block.items.length,
                                            ).padStart(2, '0')}
                                        </span>
                                    </div>
                                    <ul className="grid gap-4 sm:gap-[22px] md:grid-cols-3">
                                        {block.items.map((item, itemIndex) => {
                                            const n = itemIndex + 1;
                                            const href = stageHref(item, n);
                                            const body = (
                                                <>
                                                    <span className="mb-[22px] flex w-full items-center justify-between sm:mb-[42px]">
                                                        <span className="text-sm opacity-60">
                                                            {String(n).padStart(
                                                                2,
                                                                '0',
                                                            )}
                                                        </span>
                                                        {href && (
                                                            <ArrowUpRight
                                                                aria-hidden
                                                            />
                                                        )}
                                                    </span>
                                                    <span className="text-xs text-[#536877]">
                                                        {item.grade}
                                                    </span>
                                                    <h3 className="mt-2.5 mb-[15px] text-[1.3rem] md:text-[1.15rem] xl:text-[1.3rem]">
                                                        {item.title}
                                                    </h3>
                                                    <span className="text-sm leading-[1.9] text-[#596b7b]">
                                                        {item.body}
                                                    </span>
                                                    {href && (
                                                        <span className="mt-[30px] flex items-center gap-[18px] text-sm font-semibold">
                                                            სწავლების გვერდზე
                                                            <ArrowRight
                                                                size={18}
                                                                aria-hidden
                                                            />
                                                        </span>
                                                    )}
                                                </>
                                            );
                                            const card =
                                                'public-program-card flex h-full flex-col items-start rounded-xl p-[27px] text-left md:p-5 xl:p-[30px]';

                                            return (
                                                <li
                                                    key={item.title}
                                                    id={`stage-${n}`}
                                                    className="scroll-mt-8"
                                                >
                                                    {href ? (
                                                        <Link
                                                            href={href}
                                                            className={card}
                                                            style={{
                                                                backgroundColor:
                                                                    programTones[
                                                                        itemIndex %
                                                                            3
                                                                    ],
                                                            }}
                                                        >
                                                            {body}
                                                        </Link>
                                                    ) : (
                                                        <div
                                                            className={card}
                                                            style={{
                                                                backgroundColor:
                                                                    programTones[
                                                                        itemIndex %
                                                                            3
                                                                    ],
                                                            }}
                                                        >
                                                            {body}
                                                        </div>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
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
                                <div className="public-wrap grid gap-10 lg:grid-cols-2 lg:items-center">
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
                                        className="rounded-2xl bg-white/95 p-6 text-[var(--brand-primary,#132B45)] shadow-2xl"
                                        aria-hidden="true"
                                    >
                                        <div className="mb-5 flex items-center justify-between">
                                            <Logo
                                                brand={brand}
                                                size="compact"
                                            />
                                            <span
                                                className="flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold"
                                                style={{
                                                    backgroundColor: `${accent}1a`,
                                                    color: accent,
                                                }}
                                            >
                                                მ
                                            </span>
                                        </div>
                                        <h3 className="text-lg font-semibold">
                                            დილა მშვიდობისა 👋
                                        </h3>
                                        <p className="mt-1 text-sm text-slate-500">
                                            ილუსტრაციული მაგალითი · დემო
                                        </p>
                                        <div className="mt-4 flex items-center gap-3 rounded-lg bg-slate-50 p-3">
                                            <span
                                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                                style={{
                                                    backgroundColor: `${accent}1a`,
                                                    color: accent,
                                                }}
                                            >
                                                <BookOpen size={18} />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold">
                                                    შემდეგი გაკვეთილი
                                                </p>
                                                <p className="truncate text-xs text-slate-500">
                                                    დღის განრიგიდან
                                                </p>
                                            </div>
                                        </div>
                                        <div className="mt-3 grid grid-cols-2 gap-3">
                                            <div className="rounded-lg bg-slate-50 p-3">
                                                <p className="text-xs text-slate-500">
                                                    შეტყობინებები
                                                </p>
                                            </div>
                                            <div className="rounded-lg bg-slate-50 p-3">
                                                <p className="text-xs text-slate-500">
                                                    დოკუმენტები
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
                                <div className="space-y-4 text-lg leading-relaxed text-slate-600">
                                    {(Array.isArray(block.body)
                                        ? block.body
                                        : [block.body]
                                    ).map((paragraph, paragraphIndex) => (
                                        <p key={paragraphIndex}>{paragraph}</p>
                                    ))}
                                </div>
                            </section>
                        );

                    case 'life':
                        return (
                            <section
                                key={index}
                                id="life"
                                className="public-wrap py-16"
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
                                            style={{ color: primary }}
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
                                                className="block overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
                                            >
                                                {post.coverImageUrl && (
                                                    <img
                                                        src={post.coverImageUrl}
                                                        alt=""
                                                        className="h-40 w-full object-cover"
                                                    />
                                                )}
                                                <div className="p-6">
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
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </section>
                        );

                    case 'teachers':
                        return (
                            <section
                                key={index}
                                id="teachers"
                                className="border-t border-slate-100 bg-slate-50 py-16"
                            >
                                <div className="public-wrap">
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
                                        {featuredTeachers.length > 0 && (
                                            <Link
                                                href={
                                                    block.ctaHref ?? '/teachers'
                                                }
                                                className="inline-flex items-center gap-1 font-semibold"
                                                style={{ color: primary }}
                                            >
                                                {block.ctaLabel ??
                                                    'ყველა მასწავლებელი'}{' '}
                                                <ArrowUpRight size={16} />
                                            </Link>
                                        )}
                                    </div>

                                    {featuredTeachers.length === 0 ? (
                                        <p className="rounded-xl bg-white p-8 text-center text-slate-500">
                                            მასწავლებლების სია მალე
                                            გამოქვეყნდება.
                                        </p>
                                    ) : (
                                        <div className="-mx-6 flex snap-x gap-6 overflow-x-auto px-6 pb-2">
                                            {featuredTeachers.map((teacher) => (
                                                <Link
                                                    key={teacher.slug}
                                                    href={`/teachers/${teacher.slug}`}
                                                    className="w-40 shrink-0 snap-start text-center"
                                                >
                                                    {teacher.photoUrl ? (
                                                        <img
                                                            src={
                                                                teacher.photoUrl
                                                            }
                                                            alt={teacher.name}
                                                            className="mx-auto mb-3 h-24 w-24 rounded-full object-cover"
                                                        />
                                                    ) : (
                                                        <span
                                                            className="mx-auto mb-3 flex h-24 w-24 items-center justify-center rounded-full text-xl font-semibold"
                                                            style={{
                                                                backgroundColor: `${accent}1a`,
                                                                color: accent,
                                                            }}
                                                        >
                                                            {teacher.name.charAt(
                                                                0,
                                                            )}
                                                        </span>
                                                    )}
                                                    <p className="text-sm font-semibold">
                                                        {teacher.name}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {teacher.subject}
                                                    </p>
                                                </Link>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </section>
                        );

                    case 'contact_cta':
                        return (
                            <section
                                key={index}
                                id="contact"
                                className="border-t border-slate-200 bg-[var(--brand-secondary,#F4F7FA)] py-16"
                            >
                                <div className="public-wrap flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-center">
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
                                        className="inline-flex items-center gap-2 rounded-lg px-6 py-3 font-semibold"
                                        style={{
                                            backgroundColor: accent,
                                            color: primary,
                                        }}
                                    >
                                        დაგეგმე ვიზიტი{' '}
                                        <ArrowUpRight size={18} />
                                    </button>
                                </div>
                            </section>
                        );

                    case 'documents':
                        return (
                            <section key={index} className="public-wrap py-16">
                                {block.heading && (
                                    <h2 className="mb-10 text-2xl sm:text-3xl">
                                        {block.heading}
                                    </h2>
                                )}
                                <div className="grid gap-10 sm:grid-cols-2">
                                    {block.groups.map((group) => (
                                        <div key={group.heading}>
                                            <h3 className="mb-4 text-lg font-semibold">
                                                {group.heading}
                                            </h3>
                                            <ul className="flex flex-col gap-2">
                                                {group.items.map((item) => (
                                                    <li key={item.url}>
                                                        <a
                                                            href={item.url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="flex items-center gap-2 rounded-lg p-3 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                                                        >
                                                            <FileText
                                                                size={18}
                                                                className="shrink-0 text-slate-400"
                                                            />
                                                            <span>
                                                                {item.title}
                                                            </span>
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        );

                    default:
                        return null;
                }
            })}

            {media && <ContentMediaBlock media={media} />}

            {categorySection && <CategorySection section={categorySection} />}
        </>
    );
}
