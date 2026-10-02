import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDateTime } from '@/lib/assessment';

type Props = {
    student: { id: number; name: string };
    events: {
        id: number;
        actorName: string | null;
        action: string;
        basis: string;
        createdAt: string | null;
    }[];
};

const ACTIONS: Record<string, string> = {
    view_record: 'ჩანაწერის ნახვა',
    view_alerts: 'გაფრთხილებების ნახვა',
    view_class_alerts: 'კლასის გაფრთხილებები',
    download_care_plan: 'დოკუმენტის ჩამოტვირთვა',
    emergency_access_opened: 'გადაუდებელი წვდომის გახსნა',
    view_access_log: 'წვდომის ისტორიის ნახვა',
    profile_updated: 'პროფილის განახლება',
    condition_added: 'მდგომარეობის დამატება',
    medication_added: 'მედიკამენტის დამატება',
    contact_added: 'კონტაქტის დამატება',
    care_plan_uploaded: 'დოკუმენტის ატვირთვა',
    incident_recorded: 'ინციდენტის ჩაწერა',
    item_removed: 'ჩანაწერის წაშლა',
};

const BASES: Record<string, string> = {
    full: 'სამედიცინო პერსონალი',
    guardian: 'მშობელი',
    emergency: 'გადაუდებელი წვდომა',
    alerts_only: 'მასწავლებელი (გაფრთხილება)',
};

/**
 * Who looked at or changed this record, and on what basis. Contains no
 * health data itself.
 */
export default function HealthAccessLog({ student, events }: Props) {
    return (
        <PortalLayout>
            <Head title={`${student.name} — წვდომის ისტორია`} />
            <Link
                href={`/portal/students/${student.id}/health`}
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ჩანაწერზე დაბრუნება
            </Link>
            <h1 className="mb-6 text-2xl">{student.name} — წვდომის ისტორია</h1>

            {events.length === 0 ? (
                <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                    ჩანაწერი ჯერ არავის უნახავს.
                </p>
            ) : (
                <ol className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm">
                    {events.map((e) => (
                        <li
                            key={e.id}
                            className="flex flex-col gap-1 p-3 sm:flex-row sm:justify-between"
                        >
                            <span>
                                <strong>{e.actorName ?? '—'}</strong> ·{' '}
                                {ACTIONS[e.action] ?? e.action}
                            </span>
                            <span className="text-slate-500">
                                {BASES[e.basis] ?? e.basis} ·{' '}
                                {e.createdAt ? formatDateTime(e.createdAt) : ''}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </PortalLayout>
    );
}
