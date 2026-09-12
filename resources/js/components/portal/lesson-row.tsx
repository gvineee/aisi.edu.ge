const LINE_TONES = ['#ef8866', '#789ac1', '#78b5a2'];

type Props = {
    index: number;
    startsAt: string;
    endsAt: string;
    title: string;
    subtitle: string | null;
    cancelled?: boolean;
    durationLabel?: string;
};

/**
 * design/app/AisiConcept.tsx's `.lesson-row` — a colored vertical line,
 * time, subject/room, and a duration badge.
 */
export default function LessonRow({
    index,
    startsAt,
    endsAt,
    title,
    subtitle,
    cancelled = false,
    durationLabel = '45 წთ',
}: Props) {
    return (
        <div
            className={`flex items-center gap-4 border-b border-slate-100 py-4 last:border-0 ${cancelled ? 'opacity-50' : ''}`}
        >
            <time className="w-16 shrink-0 text-xs text-slate-500 tabular-nums">
                {startsAt}–{endsAt}
            </time>
            <span
                className="h-9 w-0.5 shrink-0 rounded-full"
                style={{
                    backgroundColor: LINE_TONES[index % LINE_TONES.length],
                }}
            />
            <div className="min-w-0 flex-1">
                <strong className="block truncate text-sm font-semibold">
                    {title}
                    {cancelled && (
                        <span className="ml-2 text-xs font-normal text-red-600">
                            გაუქმებულია
                        </span>
                    )}
                </strong>
                {subtitle && (
                    <small className="block truncate text-xs text-slate-500">
                        {subtitle}
                    </small>
                )}
            </div>
            {!cancelled && (
                <span className="shrink-0 text-xs text-slate-500">
                    {durationLabel}
                </span>
            )}
        </div>
    );
}
