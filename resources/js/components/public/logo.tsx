import type { Brand } from '@/types';

type Props = {
    brand: Brand | null;
    size?: 'default' | 'compact';
};

/**
 * The sun-and-open-book mark stays; the wordmark is set in the BPG Nino
 * Mtavruli heading font with a single quiet "სკოლა" descriptor underneath
 * (docs/03's rebrand direction — no more bilingual "სკოლა • AISI SCHOOL"
 * line). Name/logo always come from the `brand` prop, never hardcoded, so
 * a second tenant renders its own mark here without any code change.
 */
export default function Logo({ brand, size = 'default' }: Props) {
    const name = brand?.name ?? '';
    const imgSize = size === 'compact' ? 44 : 56;

    return (
        <span
            className="inline-flex items-center gap-2"
            aria-label={`სკოლა ${name}`}
        >
            {brand?.logoUrl && (
                <img
                    src={brand.logoUrl}
                    alt=""
                    width={imgSize}
                    height={imgSize}
                    className="shrink-0 object-contain"
                    style={{ width: imgSize, height: imgSize }}
                />
            )}
            <span className="flex flex-col items-start leading-none">
                <span
                    className={
                        size === 'compact'
                            ? 'text-2xl font-bold tracking-wide text-[var(--brand-primary,#132B45)]'
                            : 'text-3xl font-bold tracking-wide text-[var(--brand-primary,#132B45)]'
                    }
                    style={{ fontFamily: 'var(--font-heading)' }}
                >
                    {name}
                </span>
                <small className="mt-1 text-[10px] font-semibold tracking-[0.3em] text-slate-500">
                    სკოლა
                </small>
            </span>
        </span>
    );
}
