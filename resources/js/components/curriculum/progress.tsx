/** Shared by the curriculum list and plan pages. */
export const PLAN_STATUS: Record<string, { label: string; chip: string }> = {
    draft: { label: 'მონახაზი', chip: 'bg-slate-100 text-slate-700' },
    approved: {
        label: 'დამტკიცებული',
        chip: 'bg-emerald-100 text-emerald-900',
    },
};

export function Progress({
    covered,
    total,
    overdue,
}: {
    covered: number;
    total: number;
    overdue: number;
}) {
    const percent = total === 0 ? 0 : Math.round((covered / total) * 100);

    return (
        <div className="min-w-36">
            <div className="mb-1 flex justify-between gap-2 text-xs">
                <span>
                    {covered} / {total} ({percent}%)
                </span>
                {overdue > 0 && (
                    <span className="text-rose-700">ჩამორჩება {overdue}</span>
                )}
            </div>
            <div
                className="h-2 rounded-full bg-slate-100"
                role="progressbar"
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="გავლილი თემები"
            >
                <div
                    className={`h-2 rounded-full ${overdue > 0 ? 'bg-rose-500' : 'bg-emerald-500'}`}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}
