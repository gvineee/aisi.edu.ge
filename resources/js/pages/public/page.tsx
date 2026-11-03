import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    BookOpen,
    CalendarDays,
    ChevronRight,
    FileText,
    Heart,
    MessageCircle,
    Minus,
    Plus,
    Sparkles,
    type LucideIcon,
} from 'lucide-react';
import {
    Fragment,
    useEffect,
    useState,
    type CSSProperties,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
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
          ctaLabel?: string;
          secondaryLabel?: string;
          secondaryHref?: string;
          note?: string;
          noteEmphasis?: string;
          handNote?: string;
          photoNoteTitle?: string;
          photoNoteBody?: string;
          verticalNote?: string;
          imageUrl?: string;
          imageAlt?: string;
          /** Retired (2026-11-01): never drawn over the photo again. */
          imageCaption?: string;
      }
    | {
          type: 'values';
          items: Array<{ label: string }>;
      }
    | {
          type: 'programs';
          eyebrow?: string;
          heading: string;
          headingAccent?: string;
          aside?: string;
          linkLabel?: string;
          items: Array<{
              title: string;
              grade: string;
              body: string;
              note?: string;
              href?: string;
          }>;
      }
    | {
          type: 'history';
          eyebrow?: string;
          heading: string;
          headingAccent?: string;
          lead?: string;
          body: string;
          ctaLabel?: string;
          ctaHref?: string;
          belief?: string;
          beliefMeta?: string;
      }
    | {
          type: 'discoveries';
          eyebrow?: string;
          heading: string;
          headingAccent?: string;
          aside?: string;
          ctaLabel?: string;
          ctaHref?: string;
          items: Array<{
              label: string;
              title: string;
              body: string;
              tag?: string;
              imageUrl?: string;
              imageAlt?: string;
          }>;
      }
    | {
          type: 'portal_promo';
          eyebrow?: string;
          heading: string;
          headingAccent?: string;
          body?: string;
          ctaLabel?: string;
          ctaHref?: string;
          features?: Array<{ title: string; body?: string }>;
          sticker?: string;
      }
    | {
          type: 'life';
          eyebrow?: string;
          heading: string;
          headingAccent?: string;
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
    | { type: 'text'; heading?: string; body: string | string[] }
    | {
          type: 'contact_cta';
          eyebrow?: string;
          heading: string;
          headingAccent?: string;
          body?: string;
          ctaLabel?: string;
          sunNote?: string;
      }
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

/** Fixed per-position tints from design/app/new-vision/NewVision.tsx. */
const DISCOVERY_TINTS = ['#e7eddf', '#f6e0cc', '#dfe9f0'];
const STAGE_TINTS = ['#f7e4d5', '#e5eddd', '#e0e8f0'];
const STAGE_SYMBOLS = ['✳', '↗', '◎'];
const PORTAL_ICONS: Array<{ icon: LucideIcon; tone: string }> = [
    { icon: CalendarDays, tone: 'an-app-peach' },
    { icon: BookOpen, tone: 'an-app-green' },
    { icon: MessageCircle, tone: 'an-app-blue' },
];
const PORTAL_FEATURES = [
    { title: 'დღის განრიგი', body: 'გეგმა, რომელიც ხელთ გაქვს' },
    { title: 'აღმოჩენების პორტფოლიო', body: 'პატარა ნაბიჯების დიდი ისტორია' },
    { title: 'შეტყობინებები', body: 'მასწავლებელთან ახლოს' },
];

/** In-page anchor each block type owns (hero links such as `#school-day`). */
const BLOCK_ANCHORS: Partial<Record<Block['type'], string>> = {
    history: 'about',
    discoveries: 'school-day',
    life: 'life',
    teachers: 'teachers',
    contact_cta: 'contact',
};

const pad = (n: number) => String(n).padStart(2, '0');

/** Editor text keeps its own line breaks. */
function Lines({ text }: { text: string }) {
    return (
        <>
            {text.split('\n').map((line, index) => (
                <Fragment key={index}>
                    {index > 0 && <br />}
                    {line}
                </Fragment>
            ))}
        </>
    );
}

function SunMark({ className = '' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 120 120"
            fill="none"
            aria-hidden="true"
            focusable="false"
        >
            <circle
                cx="60"
                cy="60"
                r="22"
                stroke="currentColor"
                strokeWidth="3.5"
            />
            {Array.from({ length: 12 }, (_, i) => (
                <path
                    key={i}
                    d="M60 7V26"
                    stroke="currentColor"
                    strokeWidth="3.5"
                    strokeLinecap="round"
                    transform={`rotate(${i * 30} 60 60)`}
                />
            ))}
            <path
                d="M49 62c5 8 17 8 22 0"
                stroke="currentColor"
                strokeWidth="3"
                strokeLinecap="round"
            />
            <circle cx="51" cy="53" r="2" fill="currentColor" />
            <circle cx="69" cy="53" r="2" fill="currentColor" />
        </svg>
    );
}

/** Site paths go through Inertia; anchors stay anchors; others open apart. */
function SmartLink({
    href,
    className,
    ariaLabel,
    children,
}: {
    href: string;
    className?: string;
    ariaLabel?: string;
    children: ReactNode;
}) {
    if (href.startsWith('/')) {
        return (
            <Link href={href} className={className} aria-label={ariaLabel}>
                {children}
            </Link>
        );
    }

    if (href.startsWith('#')) {
        return (
            <a href={href} className={className} aria-label={ariaLabel}>
                {children}
            </a>
        );
    }

    return (
        <a
            href={href}
            className={className}
            aria-label={ariaLabel}
            target="_blank"
            rel="noopener noreferrer"
        >
            {children}
        </a>
    );
}

function SectionLabel({ number, text }: { number?: number; text?: string }) {
    if (!text) {
        return null;
    }

    return (
        <div className="an-section-label">
            <span>{number ? `${pad(number)} / ${text}` : text}</span>
            <span className="an-label-line" aria-hidden="true" />
        </div>
    );
}

function TwoToneHeading({
    heading,
    accent,
}: {
    heading: string;
    accent?: string;
}) {
    return (
        <>
            <Lines text={heading} />
            {accent && (
                <>
                    <br />
                    <span>{accent}</span>
                </>
            )}
        </>
    );
}

function formatPostDate(iso: string) {
    return new Date(iso).toLocaleDateString('ka-GE', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Tbilisi',
    });
}

/**
 * Renders any tenant CMS page — home included — from its typed blocks.
 * One rendering path for every page keeps the block set (and this
 * component) the single thing to maintain, instead of one file per page.
 * Markup and class names follow design/app/new-vision/NewVision.tsx; the
 * styles live in resources/css/new-vision.css.
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
    const openVisitDialog = useOpenVisitDialog();
    const isHome = page.slug === 'home';
    const anchors = new Set(
        page.blocks.map((block) => BLOCK_ANCHORS[block.type]).filter(Boolean),
    );

    // "01 / …" numbering of the labelled sections, as in the design —
    // only when a page has more than one of them.
    const labelled = page.blocks.filter(
        (block) =>
            ['history', 'discoveries', 'programs', 'life'].includes(
                block.type,
            ) &&
            'eyebrow' in block &&
            block.eyebrow,
    );
    const sectionNumber = (block: Block) =>
        labelled.length > 1
            ? labelled.indexOf(block) + 1 || undefined
            : undefined;

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

            {breadcrumb && (
                <CategoryBreadcrumbNav
                    breadcrumb={breadcrumb}
                    className="an-wrap pt-6"
                />
            )}

            {/* Every page needs one h1; hero-less CMS pages get the title for assistive tech. */}
            {!page.blocks.some((block) => block.type === 'hero') && (
                <h1 className="sr-only">{page.title}</h1>
            )}

            {page.blocks.map((block, index) => {
                switch (block.type) {
                    case 'hero': {
                        const visitLabel =
                            block.ctaLabel ??
                            `მოდი, გაიცანი ${brand?.name ?? ''}`.trim();
                        const secondary =
                            block.secondaryLabel &&
                            block.secondaryHref &&
                            (!block.secondaryHref.startsWith('#') ||
                                anchors.has(block.secondaryHref.slice(1)))
                                ? {
                                      label: block.secondaryLabel,
                                      href: block.secondaryHref,
                                  }
                                : null;
                        const heading = (
                            <>
                                <Lines text={block.heading} />
                                {block.headingLine2 && (
                                    <>
                                        <br />
                                        {block.headingLine2}
                                    </>
                                )}
                                {block.headingEmphasis && (
                                    <>
                                        <br />
                                        <span className="an-wordmark">
                                            {block.headingEmphasis}
                                            <svg
                                                viewBox="0 0 520 25"
                                                fill="none"
                                                aria-hidden="true"
                                                focusable="false"
                                            >
                                                <path
                                                    d="M5 16C120 2 345 1 512 12M18 23c172-15 310-14 473-4"
                                                    stroke="currentColor"
                                                    strokeWidth="4"
                                                    strokeLinecap="round"
                                                />
                                            </svg>
                                        </span>
                                    </>
                                )}
                            </>
                        );
                        const actions = (
                            <div className="an-hero-actions">
                                <button
                                    type="button"
                                    className="an-button an-button-orange"
                                    onClick={openVisitDialog}
                                >
                                    {visitLabel}{' '}
                                    <span>
                                        <ArrowUpRight
                                            size={22}
                                            aria-hidden="true"
                                        />
                                    </span>
                                </button>
                                {secondary && (
                                    <SmartLink
                                        href={secondary.href}
                                        className="an-text-link"
                                    >
                                        {secondary.label}{' '}
                                        <span
                                            className="an-play"
                                            aria-hidden="true"
                                        >
                                            <ChevronRight size={17} />
                                        </span>
                                    </SmartLink>
                                )}
                            </div>
                        );
                        const image =
                            block.imageUrl ??
                            (isHome ? brand?.heroImageUrl : null);

                        // Inner pages: the same type and rhythm, no photo
                        // composition unless the editor gives one.
                        if (!image) {
                            return (
                                <section
                                    key={index}
                                    className="an-page-hero an-wrap"
                                >
                                    {block.eyebrow && (
                                        <p className="an-eyebrow">
                                            <span
                                                className="an-live-dot"
                                                aria-hidden="true"
                                            />{' '}
                                            {block.eyebrow}
                                        </p>
                                    )}
                                    <h1>{heading}</h1>
                                    {block.body && (
                                        <p className="an-hero-lead">
                                            <Lines text={block.body} />
                                        </p>
                                    )}
                                    {actions}
                                    <SunMark className="an-page-hero-sun" />
                                </section>
                            );
                        }

                        return (
                            <section key={index} className="an-hero an-wrap">
                                <div className="an-hero-copy">
                                    {block.eyebrow && (
                                        <p className="an-eyebrow">
                                            <span
                                                className="an-live-dot"
                                                aria-hidden="true"
                                            />{' '}
                                            {block.eyebrow}
                                        </p>
                                    )}
                                    <h1>{heading}</h1>
                                    {block.body && (
                                        <p className="an-hero-lead">
                                            <Lines text={block.body} />
                                        </p>
                                    )}
                                    {actions}
                                    {block.note && (
                                        <div className="an-hero-foot">
                                            <span
                                                className="an-tiny-sun"
                                                aria-hidden="true"
                                            >
                                                ✳
                                            </span>
                                            <p>
                                                {block.note}
                                                {block.noteEmphasis && (
                                                    <>
                                                        <br />
                                                        <strong>
                                                            {block.noteEmphasis}
                                                        </strong>
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                    )}
                                </div>
                                <div className="an-hero-art">
                                    <div className="an-hero-image">
                                        <img
                                            src={image}
                                            alt={block.imageAlt ?? ''}
                                            width={1536}
                                            height={1024}
                                            fetchPriority="high"
                                        />
                                    </div>
                                    {block.handNote && (
                                        <div className="an-hand-note">
                                            <Lines text={block.handNote} />
                                            <svg
                                                viewBox="0 0 100 60"
                                                fill="none"
                                                aria-hidden="true"
                                                focusable="false"
                                            >
                                                <path
                                                    d="M5 8c30 2 62 7 58 35m-14-7 14 14 17-16"
                                                    stroke="currentColor"
                                                    strokeWidth="2.8"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                />
                                            </svg>
                                        </div>
                                    )}
                                    <SunMark className="an-hero-sun" />
                                    {block.photoNoteTitle && (
                                        <div className="an-photo-note">
                                            <span aria-hidden="true">
                                                <Sparkles size={20} />
                                            </span>
                                            <div>
                                                <strong>
                                                    {block.photoNoteTitle}
                                                </strong>
                                                {block.photoNoteBody && (
                                                    <p>{block.photoNoteBody}</p>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                    {block.verticalNote && (
                                        <p
                                            className="an-vertical-note"
                                            aria-hidden="true"
                                        >
                                            {block.verticalNote}
                                        </p>
                                    )}
                                </div>
                            </section>
                        );
                    }

                    case 'values':
                        return (
                            <div key={index} className="an-values-ribbon">
                                <ul className="an-wrap" role="list">
                                    {block.items.map((item, itemIndex) => (
                                        <Fragment key={itemIndex}>
                                            {itemIndex > 0 && (
                                                <li
                                                    className="an-ribbon-flower"
                                                    aria-hidden="true"
                                                >
                                                    ✳
                                                </li>
                                            )}
                                            <li>{item.label}</li>
                                        </Fragment>
                                    ))}
                                    <li aria-hidden="true">
                                        <ArrowUpRight />
                                    </li>
                                </ul>
                            </div>
                        );

                    case 'history':
                        return (
                            <section
                                key={index}
                                id="about"
                                className="an-intro an-wrap"
                            >
                                <SectionLabel
                                    number={sectionNumber(block)}
                                    text={block.eyebrow}
                                />
                                <div className="an-intro-grid">
                                    <h2>
                                        <TwoToneHeading
                                            heading={block.heading}
                                            accent={block.headingAccent}
                                        />
                                    </h2>
                                    <div>
                                        {block.lead && <p>{block.lead}</p>}
                                        <p>{block.body}</p>
                                        {block.ctaHref && (
                                            <SmartLink
                                                href={block.ctaHref}
                                                className="an-text-link"
                                            >
                                                {block.ctaLabel ?? 'გაიგე მეტი'}{' '}
                                                <ArrowUpRight
                                                    size={20}
                                                    aria-hidden="true"
                                                />
                                            </SmartLink>
                                        )}
                                    </div>
                                </div>
                                {block.belief && (
                                    <div className="an-belief">
                                        <Heart size={22} aria-hidden="true" />
                                        <p>{block.belief}</p>
                                        {block.beliefMeta && (
                                            <span>{block.beliefMeta}</span>
                                        )}
                                    </div>
                                )}
                            </section>
                        );

                    case 'discoveries':
                        return (
                            <DiscoveriesSection
                                key={index}
                                block={block}
                                blockIndex={index}
                                number={sectionNumber(block)}
                            />
                        );

                    case 'programs':
                        return (
                            <StagesSection
                                key={index}
                                block={block}
                                blockIndex={index}
                                number={sectionNumber(block)}
                                isLearningPage={page.slug === 'learning'}
                            />
                        );

                    case 'portal_promo': {
                        const features = block.features?.length
                            ? block.features
                            : PORTAL_FEATURES;

                        return (
                            <section key={index} className="an-portal-section">
                                <div className="an-wrap an-portal-grid">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="an-eyebrow">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h2>
                                            <TwoToneHeading
                                                heading={block.heading}
                                                accent={block.headingAccent}
                                            />
                                        </h2>
                                        {block.body && (
                                            <p className="an-portal-description">
                                                {block.body}
                                            </p>
                                        )}
                                        <SmartLink
                                            href={block.ctaHref ?? '/login'}
                                            className="an-button an-button-cream"
                                        >
                                            {block.ctaLabel ?? 'შედი პორტალში'}{' '}
                                            <span>
                                                <ArrowUpRight
                                                    size={22}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </SmartLink>
                                    </div>
                                    <figure className="an-portal-visual">
                                        <figcaption className="sr-only">
                                            პორტალის შესაძლებლობების ილუსტრაცია
                                        </figcaption>
                                        <span
                                            className="an-portal-orbit an-orbit-one"
                                            aria-hidden="true"
                                        />
                                        <span
                                            className="an-portal-orbit an-orbit-two"
                                            aria-hidden="true"
                                        />
                                        <div className="an-portal-card">
                                            <div
                                                className="an-app-header"
                                                aria-hidden="true"
                                            >
                                                <Logo
                                                    brand={brand}
                                                    size="plain"
                                                />
                                                <span>
                                                    ჩემი სივრცე{' '}
                                                    <span className="an-avatar">
                                                        {(
                                                            brand?.shortName ??
                                                            brand?.name ??
                                                            ''
                                                        ).charAt(0)}
                                                    </span>
                                                </span>
                                            </div>
                                            <h3>დღე მშვიდობისა ☀</h3>
                                            <p>ყველაფერი მნიშვნელოვანი — აქ.</p>
                                            <ul className="an-app-list">
                                                {features.map(
                                                    (feature, featureIndex) => {
                                                        const {
                                                            icon: Icon,
                                                            tone,
                                                        } =
                                                            PORTAL_ICONS[
                                                                featureIndex %
                                                                    PORTAL_ICONS.length
                                                            ];

                                                        return (
                                                            <li
                                                                key={
                                                                    featureIndex
                                                                }
                                                                className="an-app-item"
                                                            >
                                                                <span
                                                                    className={`an-app-icon ${tone}`}
                                                                    aria-hidden="true"
                                                                >
                                                                    <Icon
                                                                        size={
                                                                            23
                                                                        }
                                                                    />
                                                                </span>
                                                                <div>
                                                                    <strong>
                                                                        {
                                                                            feature.title
                                                                        }
                                                                    </strong>
                                                                    {feature.body && (
                                                                        <p>
                                                                            {
                                                                                feature.body
                                                                            }
                                                                        </p>
                                                                    )}
                                                                </div>
                                                                <ArrowUpRight
                                                                    size={17}
                                                                    aria-hidden="true"
                                                                />
                                                            </li>
                                                        );
                                                    },
                                                )}
                                            </ul>
                                        </div>
                                        {block.sticker && (
                                            <span className="an-portal-sticker">
                                                <Heart
                                                    size={18}
                                                    fill="currentColor"
                                                    aria-hidden="true"
                                                />{' '}
                                                {block.sticker}
                                            </span>
                                        )}
                                    </figure>
                                </div>
                            </section>
                        );
                    }

                    case 'text':
                        return (
                            <section
                                key={index}
                                className="an-text-block an-wrap"
                            >
                                {block.heading && <h2>{block.heading}</h2>}
                                <div className="an-text-body">
                                    {/* Imported pages keep their paragraphs
                                        as blank-line-separated text. */}
                                    {(Array.isArray(block.body)
                                        ? block.body
                                        : block.body
                                              .split(/\n\s*\n/)
                                              .map((part) => part.trim())
                                              .filter(Boolean)
                                    ).map((paragraph, paragraphIndex) => (
                                        <p key={paragraphIndex}>{paragraph}</p>
                                    ))}
                                </div>
                            </section>
                        );

                    case 'life': {
                        // The home page shows the design's asymmetric pair.
                        const posts = isHome
                            ? latestPosts.slice(0, 2)
                            : latestPosts;

                        return (
                            <section
                                key={index}
                                id="life"
                                className="an-stories an-wrap"
                            >
                                <SectionLabel
                                    number={sectionNumber(block)}
                                    text={block.eyebrow}
                                />
                                <div className="an-stories-heading">
                                    <h2>
                                        <TwoToneHeading
                                            heading={block.heading}
                                            accent={block.headingAccent}
                                        />
                                    </h2>
                                    {posts.length > 0 && (
                                        <SmartLink
                                            href={block.ctaHref ?? '/news'}
                                            className="an-text-link"
                                        >
                                            {block.ctaLabel ?? 'ყველა ამბავი'}{' '}
                                            <ArrowUpRight
                                                size={20}
                                                aria-hidden="true"
                                            />
                                        </SmartLink>
                                    )}
                                </div>

                                {posts.length === 0 ? (
                                    <p className="an-empty-note">
                                        სიახლეები მალე გამოქვეყნდება.
                                    </p>
                                ) : (
                                    <div
                                        className={`an-stories-grid${isHome ? '' : ' an-stories-grid-wide'}`}
                                    >
                                        {posts.map((post) => (
                                            <Link
                                                key={post.slug}
                                                href={`/news/${post.slug}`}
                                                className="an-story-card"
                                            >
                                                <div
                                                    className={`an-story-photo${post.coverImageUrl ? '' : ' an-story-photo-empty'}`}
                                                >
                                                    {post.coverImageUrl ? (
                                                        <img
                                                            src={
                                                                post.coverImageUrl
                                                            }
                                                            alt=""
                                                            loading="lazy"
                                                        />
                                                    ) : (
                                                        <SunMark />
                                                    )}
                                                    <span
                                                        className="an-story-open"
                                                        aria-hidden="true"
                                                    >
                                                        <ArrowUpRight
                                                            size={25}
                                                        />
                                                    </span>
                                                </div>
                                                {post.publishedAt && (
                                                    <div className="an-story-meta">
                                                        <time
                                                            dateTime={
                                                                post.publishedAt
                                                            }
                                                        >
                                                            {formatPostDate(
                                                                post.publishedAt,
                                                            )}
                                                        </time>
                                                    </div>
                                                )}
                                                <h3>{post.title}</h3>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </section>
                        );
                    }

                    case 'teachers':
                        return (
                            <section
                                key={index}
                                id="teachers"
                                className="an-stories an-wrap"
                            >
                                <SectionLabel text={block.eyebrow} />
                                <div className="an-stories-heading">
                                    <h2>{block.heading}</h2>
                                    {featuredTeachers.length > 0 && (
                                        <SmartLink
                                            href={block.ctaHref ?? '/teachers'}
                                            className="an-text-link"
                                        >
                                            {block.ctaLabel ??
                                                'ყველა მასწავლებელი'}{' '}
                                            <ArrowUpRight
                                                size={20}
                                                aria-hidden="true"
                                            />
                                        </SmartLink>
                                    )}
                                </div>

                                {featuredTeachers.length === 0 ? (
                                    <p className="an-empty-note">
                                        მასწავლებლების სია მალე გამოქვეყნდება.
                                    </p>
                                ) : (
                                    <div className="-mx-5 mt-10 flex snap-x gap-6 overflow-x-auto px-5 pb-2">
                                        {featuredTeachers.map((teacher) => (
                                            <Link
                                                key={teacher.slug}
                                                href={`/teachers/${teacher.slug}`}
                                                className="w-40 shrink-0 snap-start text-center"
                                            >
                                                {teacher.photoUrl ? (
                                                    <img
                                                        src={teacher.photoUrl}
                                                        alt={teacher.name}
                                                        className="mx-auto mb-3 h-24 w-24 rounded-full object-cover"
                                                    />
                                                ) : (
                                                    <span className="mx-auto mb-3 flex h-24 w-24 items-center justify-center rounded-full bg-[#f1ece1] text-xl font-semibold text-[#b04420]">
                                                        {teacher.name.charAt(0)}
                                                    </span>
                                                )}
                                                <p className="text-sm font-semibold">
                                                    {teacher.name}
                                                </p>
                                                <p className="text-xs text-[#5a6865]">
                                                    {teacher.subject}
                                                </p>
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
                                className="an-final-section"
                            >
                                <div className="an-wrap an-final-grid">
                                    <div>
                                        {block.eyebrow && (
                                            <p className="an-eyebrow">
                                                {block.eyebrow}
                                            </p>
                                        )}
                                        <h2>
                                            <TwoToneHeading
                                                heading={block.heading}
                                                accent={block.headingAccent}
                                            />
                                        </h2>
                                        {block.body && (
                                            <p className="an-final-body">
                                                {block.body}
                                            </p>
                                        )}
                                        {/* Inner pages (contact, admissions) keep the school's contact details here. */}
                                        {!isHome && brand && (
                                            <ul className="an-final-contact">
                                                {brand.contact.phone && (
                                                    <li>
                                                        <a
                                                            href={`tel:${brand.contact.phone.replace(/\s+/g, '')}`}
                                                        >
                                                            {
                                                                brand.contact
                                                                    .phone
                                                            }
                                                        </a>
                                                    </li>
                                                )}
                                                {brand.contact.email && (
                                                    <li>
                                                        <a
                                                            href={`mailto:${brand.contact.email}`}
                                                        >
                                                            {
                                                                brand.contact
                                                                    .email
                                                            }
                                                        </a>
                                                    </li>
                                                )}
                                                {brand.contact.address && (
                                                    <li>
                                                        {brand.contact.address}
                                                    </li>
                                                )}
                                            </ul>
                                        )}
                                        <button
                                            type="button"
                                            className="an-button an-button-navy"
                                            onClick={openVisitDialog}
                                        >
                                            {block.ctaLabel ?? 'დაგეგმე ვიზიტი'}{' '}
                                            <span>
                                                <ArrowUpRight
                                                    size={22}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </button>
                                    </div>
                                    <div
                                        className="an-final-sun"
                                        aria-hidden="true"
                                    >
                                        <SunMark />
                                        {block.sunNote && (
                                            <p>
                                                <Lines text={block.sunNote} />
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </section>
                        );

                    case 'documents':
                        return (
                            <section
                                key={index}
                                className="an-text-block an-wrap"
                            >
                                {block.heading && <h2>{block.heading}</h2>}
                                <div className="mt-10 grid gap-10 sm:grid-cols-2">
                                    {block.groups.map((group) => (
                                        <div key={group.heading}>
                                            <h3 className="mb-4 text-xl">
                                                {group.heading}
                                            </h3>
                                            <ul className="flex flex-col gap-2">
                                                {group.items.map((item) => (
                                                    <li key={item.url}>
                                                        <a
                                                            href={item.url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="flex min-h-11 items-center gap-2 rounded-xl bg-white/70 p-3 text-sm ring-1 ring-[#dedfd3] hover:bg-[#e7eddf]"
                                                        >
                                                            <FileText
                                                                size={18}
                                                                className="shrink-0 text-[#69746f]"
                                                                aria-hidden="true"
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

            {media && (
                <ContentMediaBlock
                    media={media}
                    className="an-wrap an-page-media pb-12"
                />
            )}

            {categorySection && <CategorySection section={categorySection} />}
        </>
    );
}

/** "One day" tabs: arrow keys move between tabs, as in the design. */
function DiscoveriesSection({
    block,
    blockIndex,
    number,
}: {
    block: Extract<Block, { type: 'discoveries' }>;
    blockIndex: number;
    number?: number;
}) {
    const [active, setActive] = useState(0);
    const items = block.items ?? [];
    const current = items[Math.min(active, items.length - 1)];
    const tabId = (i: number) => `an-tab-${blockIndex}-${i}`;
    const panelId = `an-discovery-panel-${blockIndex}`;

    const onKey = (event: KeyboardEvent<HTMLButtonElement>, i: number) => {
        const moves: Record<string, number> = {
            ArrowRight: (i + 1) % items.length,
            ArrowLeft: (i - 1 + items.length) % items.length,
            Home: 0,
            End: items.length - 1,
        };

        if (event.key in moves) {
            event.preventDefault();
            setActive(moves[event.key]);
            document.getElementById(tabId(moves[event.key]))?.focus();
        }
    };

    return (
        <section className="an-day-section" id="school-day">
            <div className="an-wrap">
                <SectionLabel number={number} text={block.eyebrow} />
                <div className="an-day-heading">
                    <h2>
                        <TwoToneHeading
                            heading={block.heading}
                            accent={block.headingAccent}
                        />
                    </h2>
                    {block.aside && (
                        <p>
                            <Lines text={block.aside} />
                        </p>
                    )}
                    <SunMark className="an-day-sun" />
                </div>
                {current && (
                    <>
                        <div
                            className="an-day-tabs"
                            role="tablist"
                            aria-label={block.eyebrow ?? block.heading}
                        >
                            {items.map((item, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    id={tabId(i)}
                                    role="tab"
                                    aria-selected={active === i}
                                    aria-controls={panelId}
                                    tabIndex={active === i ? 0 : -1}
                                    onClick={() => setActive(i)}
                                    onKeyDown={(event) => onKey(event, i)}
                                >
                                    <span>{pad(i + 1)}</span>
                                    {item.label}
                                    <ArrowUpRight
                                        size={18}
                                        aria-hidden="true"
                                    />
                                </button>
                            ))}
                        </div>
                        <div
                            className="an-discovery"
                            id={panelId}
                            role="tabpanel"
                            aria-labelledby={tabId(active)}
                            tabIndex={0}
                        >
                            <div className="an-discovery-photo">
                                {current.imageUrl && (
                                    <img
                                        key={current.imageUrl}
                                        src={current.imageUrl}
                                        alt={current.imageAlt ?? ''}
                                        loading="lazy"
                                    />
                                )}
                                <span
                                    className="an-photo-index"
                                    aria-hidden="true"
                                >
                                    {pad(active + 1)}
                                    <span>/ {pad(items.length)}</span>
                                </span>
                            </div>
                            <div
                                className="an-discovery-story"
                                style={{
                                    backgroundColor:
                                        DISCOVERY_TINTS[
                                            active % DISCOVERY_TINTS.length
                                        ],
                                }}
                            >
                                {current.tag && (
                                    <span className="an-small-tag">
                                        {current.tag}
                                    </span>
                                )}
                                <h3>
                                    <Lines text={current.title} />
                                </h3>
                                <p>{current.body}</p>
                                {block.ctaHref && (
                                    <SmartLink
                                        href={block.ctaHref}
                                        className="an-circle-link"
                                        ariaLabel={
                                            block.ctaLabel ?? 'გაიგე მეტი'
                                        }
                                    >
                                        <ArrowUpRight
                                            size={29}
                                            aria-hidden="true"
                                        />
                                    </SmartLink>
                                )}
                                <span
                                    className="an-story-scribble"
                                    aria-hidden="true"
                                >
                                    ↝
                                </span>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}

/**
 * School stages as a one-open-at-a-time accordion. `/learning#stage-2`
 * opens the second stage.
 */
function StagesSection({
    block,
    blockIndex,
    number,
    isLearningPage,
}: {
    block: Extract<Block, { type: 'programs' }>;
    blockIndex: number;
    number?: number;
    isLearningPage: boolean;
}) {
    const [active, setActive] = useState(0);

    useEffect(() => {
        const openFromHash = () => {
            const match = /^#stage-(\d+)$/.exec(window.location.hash);

            if (match) {
                setActive(Number(match[1]) - 1);
            }
        };

        openFromHash();
        window.addEventListener('hashchange', openFromHash);

        return () => window.removeEventListener('hashchange', openFromHash);
    }, []);

    return (
        <section className="an-learning an-wrap" id="learning-paths">
            <SectionLabel number={number} text={block.eyebrow} />
            <div className="an-learning-heading">
                <h2>
                    <TwoToneHeading
                        heading={block.heading}
                        accent={block.headingAccent}
                    />
                </h2>
                {block.aside && (
                    <p>
                        <Lines text={block.aside} />
                    </p>
                )}
            </div>
            <div className="an-stages">
                {block.items.map((item, i) => {
                    const n = i + 1;
                    const open = active === i;
                    const bodyId = `an-stage-body-${blockIndex}-${i}`;
                    // The learning page is itself the description.
                    const href =
                        item.href ??
                        (isLearningPage ? null : `/learning#stage-${n}`);

                    return (
                        <article
                            key={i}
                            id={`stage-${n}`}
                            className={`an-stage${open ? ' an-stage-active' : ''}`}
                            style={
                                {
                                    '--stage-color':
                                        STAGE_TINTS[i % STAGE_TINTS.length],
                                } as CSSProperties
                            }
                        >
                            <button
                                type="button"
                                className="an-stage-toggle"
                                aria-expanded={open}
                                aria-controls={bodyId}
                                onClick={() => setActive(open ? -1 : i)}
                            >
                                <span className="an-stage-number">
                                    {pad(n)}
                                </span>
                                <span className="an-stage-label">
                                    {item.grade}
                                </span>
                                <h3>{item.title}</h3>
                                <span
                                    className="an-stage-plus"
                                    aria-hidden="true"
                                >
                                    {open ? (
                                        <Minus size={24} />
                                    ) : (
                                        <Plus size={24} />
                                    )}
                                </span>
                            </button>
                            <div
                                id={bodyId}
                                className="an-stage-body"
                                hidden={!open}
                            >
                                <span
                                    className="an-stage-symbol"
                                    aria-hidden="true"
                                >
                                    {STAGE_SYMBOLS[i % STAGE_SYMBOLS.length]}
                                </span>
                                <div>
                                    <p>{item.body}</p>
                                    {href && (
                                        <SmartLink
                                            href={href}
                                            className="an-text-link"
                                        >
                                            {block.linkLabel ??
                                                'სწავლის შესახებ'}{' '}
                                            <ArrowUpRight
                                                size={19}
                                                aria-hidden="true"
                                            />
                                        </SmartLink>
                                    )}
                                </div>
                                {item.note && (
                                    <span className="an-stage-note">
                                        {item.note}
                                    </span>
                                )}
                            </div>
                        </article>
                    );
                })}
            </div>
        </section>
    );
}
