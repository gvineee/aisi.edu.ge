import { Head, usePage } from '@inertiajs/react';
import { ArrowUpRight, BookOpen } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { useOpenVisitDialog } from '@/components/public/visit-dialog-context';
import type { Brand } from '@/types';

type Block =
    | { type: 'hero'; eyebrow?: string; heading: string; body?: string }
    | {
          type: 'programs';
          heading: string;
          items: Array<{ title: string; grade: string; body: string }>;
      }
    | { type: 'life'; heading: string }
    | { type: 'text'; heading: string; body: string }
    | { type: 'contact_cta'; heading: string; body?: string };

type Props = {
    page: {
        slug: string;
        title: string;
        excerpt: string | null;
        blocks: Block[];
        seoTitle: string | null;
        seoDescription: string | null;
    };
};

/**
 * Renders any tenant CMS page — home included — from its typed blocks.
 * One rendering path for every page keeps the block set (and this
 * component) the single thing to maintain, instead of one file per page.
 */
export default function CmsPage({ page }: Props) {
    const { brand } = usePage<{ brand: Brand | null }>().props;
    const accent = brand?.colors.accent ?? '#F5683C';
    const openVisitDialog = useOpenVisitDialog();

    return (
        <PublicLayout>
            <Head title={page.seoTitle ?? page.title}>
                {page.seoDescription && (
                    <meta name="description" content={page.seoDescription} />
                )}
            </Head>

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
                                        </h1>
                                        {block.body && (
                                            <p className="mt-6 max-w-md text-lg text-slate-600">
                                                {block.body}
                                            </p>
                                        )}
                                    </div>
                                    <div
                                        className="flex h-72 items-center justify-center rounded-[130px_14px_14px_14px] bg-slate-100 sm:h-96"
                                        aria-hidden="true"
                                    >
                                        <BookOpen
                                            className="h-16 w-16"
                                            style={{ color: accent }}
                                        />
                                    </div>
                                </div>
                            </section>
                        );

                    case 'programs':
                        return (
                            <section
                                key={index}
                                className="bg-slate-50 py-16 sm:py-24"
                            >
                                <div className="mx-auto max-w-6xl px-6">
                                    <h2 className="mb-10 text-2xl sm:text-3xl">
                                        {block.heading}
                                    </h2>
                                    <div className="grid gap-6 sm:grid-cols-3">
                                        {block.items.map((item) => (
                                            <div
                                                key={item.title}
                                                className="rounded-xl bg-white p-7 shadow-sm ring-1 ring-slate-200"
                                            >
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
                                        ))}
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
                                className="mx-auto max-w-6xl px-6 py-16"
                            >
                                <h2 className="text-2xl sm:text-3xl">
                                    {block.heading}
                                </h2>
                            </section>
                        );

                    case 'contact_cta':
                        return (
                            <section
                                key={index}
                                className="border-t border-slate-200 bg-[var(--brand-secondary,#F4F7FA)] py-16"
                            >
                                <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 px-6 sm:flex-row sm:items-center">
                                    <div>
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
                                            backgroundColor:
                                                brand?.colors.primary ??
                                                '#132B45',
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
