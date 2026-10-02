const STYLES: Record<string, { label: string; className: string }> = {
    high: { label: 'მაღალი', className: 'bg-red-100 text-red-800' },
    medium: { label: 'საშუალო', className: 'bg-amber-100 text-amber-900' },
    low: { label: 'დაბალი', className: 'bg-slate-100 text-slate-700' },
};

export default function SeverityBadge({ severity }: { severity: string }) {
    const style = STYLES[severity] ?? STYLES.low;

    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${style.className}`}
        >
            {style.label} მნიშვნელობა
        </span>
    );
}
