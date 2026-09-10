import type { Brand } from '@/types';

type Props = {
    brand: Brand | null;
    size?: 'default' | 'compact' | 'portal';
};

/**
 * Renders the tenant's approved logo as a single complete horizontal
 * lockup (icon + wordmark already combined in one image, per the user's
 * approved `aisi-drawn-logo.png` — no second, separately-typed name is
 * drawn alongside it anymore). The image always comes from `brand.logoUrl`
 * (tenant brand settings, CLAUDE.md invariant #8), never hardcoded, so a
 * second tenant's own logo renders here with zero code change. When a
 * tenant has no logo configured yet, the name renders as plain text so the
 * header never goes blank.
 *
 * Reference box sizes (design/04-design-handoff.md): 210×70 desktop header,
 * 150×50 mobile, 174×58 portal header — all a fixed 3:1 aspect ratio, so
 * `object-contain` never needs to crop or stretch the mark.
 */
export default function Logo({ brand, size = 'default' }: Props) {
    const name = brand?.name ?? '';

    const boxClassName =
        size === 'portal'
            ? 'h-[58px] w-[174px]'
            : size === 'compact'
              ? 'h-[50px] w-[150px]'
              : 'h-[50px] w-[150px] md:h-[70px] md:w-[210px]';

    if (!brand?.logoUrl) {
        return (
            <span
                className="font-bold text-[var(--brand-primary,#132B45)]"
                style={{ fontFamily: 'var(--font-heading)' }}
                aria-label={`სკოლა ${name}`}
            >
                {name}
            </span>
        );
    }

    return (
        <img
            src={brand.logoUrl}
            alt={`სკოლა ${name}`}
            className={`${boxClassName} shrink-0 object-contain object-left`}
        />
    );
}
