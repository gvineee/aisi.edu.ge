import type { LucideIcon } from 'lucide-react';

export type Stat = {
    key: string;
    icon: LucideIcon;
    value: string;
    label: string;
    tone: 'blue' | 'peach' | 'green';
};

const TONE_STYLES: Record<Stat['tone'], { bg: string; fg: string }> = {
    blue: { bg: '#EAF1F9', fg: '#3E6FA6' },
    peach: { bg: '#FFEDE5', fg: '#C84925' },
    green: { bg: '#E5F2ED', fg: '#247452' },
};

/**
 * design/app/AisiConcept.tsx's `.stat-grid` — a row of small metric cards.
 * Only ever fed real, server-resolved numbers; a metric with no real data
 * source yet is simply omitted from the array rather than shown with an
 * invented value (CLAUDE.md invariant #10).
 */
export default function StatGrid({ stats }: { stats: Stat[] }) {
    if (stats.length === 0) {
        return null;
    }

    return (
        <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            {stats.map((stat) => {
                const tone = TONE_STYLES[stat.tone];
                const Icon = stat.icon;

                return (
                    <div
                        key={stat.key}
                        className="rounded-xl border border-slate-200 bg-white p-5"
                    >
                        <span
                            className="flex h-11 w-11 items-center justify-center rounded-lg"
                            style={{
                                backgroundColor: tone.bg,
                                color: tone.fg,
                            }}
                        >
                            <Icon size={21} />
                        </span>
                        <strong className="mt-3 block text-2xl font-bold">
                            {stat.value}
                        </strong>
                        <p className="mt-1 text-xs text-slate-500">
                            {stat.label}
                        </p>
                    </div>
                );
            })}
        </div>
    );
}
