import { Head, Link } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import PlatformLayout from '@/layouts/platform-layout';
import { formatDateTime } from '@/lib/assessment';

type Props = {
    tenant: { id: number; name: string };
    expiresAt: string | null;
    counts: { activeStudents: number; members: Record<string, number> };
    features: Record<string, boolean>;
};

/** Read-only support session view, always under a visible banner. */
export default function SupportView({
    tenant,
    expiresAt,
    counts,
    features,
}: Props) {
    return (
        <PlatformLayout>
            <Head title={`Support — ${tenant.name}`} />
            <p
                role="status"
                className="mb-6 flex items-center gap-2 rounded-lg bg-amber-100 p-4 text-sm font-medium text-amber-950"
            >
                <Eye aria-hidden /> მხარდაჭერის სესია ({tenant.name}) — მხოლოდ
                სანახავად
                {expiresAt ? `, ${formatDateTime(expiresAt)}-მდე` : ''}. ნახვა
                აღრიცხულია.
            </p>
            <h1 className="mb-4 text-2xl">{tenant.name}</h1>
            <dl className="mb-6 grid gap-3 sm:grid-cols-3">
                <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                    <dt className="text-xs text-slate-500">აქტიური მოსწავლე</dt>
                    <dd className="text-xl font-semibold">
                        {counts.activeStudents}
                    </dd>
                </div>
                {Object.entries(counts.members).map(([role, count]) => (
                    <div
                        key={role}
                        className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                    >
                        <dt className="text-xs text-slate-500">{role}</dt>
                        <dd className="text-xl font-semibold">{count}</dd>
                    </div>
                ))}
            </dl>
            <h2 className="mb-2 text-lg">მოდულები</h2>
            <ul className="mb-6 flex flex-wrap gap-2 text-sm">
                {Object.entries(features).map(([f, on]) => (
                    <li
                        key={f}
                        className={`rounded-full px-3 py-1 ${on ? 'bg-emerald-100' : 'bg-slate-200'}`}
                    >
                        {f}: {on ? 'ჩართული' : 'გამორთული'}
                    </li>
                ))}
            </ul>
            <Link
                href={`/platform-admin/tenants/${tenant.id}`}
                className="inline-flex min-h-11 items-center text-sm underline"
            >
                უკან
            </Link>
        </PlatformLayout>
    );
}
