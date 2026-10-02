export default function AssessmentStatusBadge({ status }: { status: string }) {
    return status === 'published' ? (
        <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800">
            გამოქვეყნებული
        </span>
    ) : (
        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
            დრაფტი
        </span>
    );
}
