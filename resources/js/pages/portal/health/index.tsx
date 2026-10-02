import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { HeartPulse, KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/assessment';
import SeverityBadge from '@/components/portal/severity-badge';

type Props = {
    alerts: {
        studentId: number;
        studentName: string;
        className: string | null;
        alerts: { severity: string; text: string | null }[];
    }[];
    students: { id: number; name: string; className: string | null }[];
    reviewQueue: {
        id: number;
        studentId: number;
        studentName: string | null;
        userName: string | null;
        reason: string;
        createdAt: string | null;
        expiresAt: string;
    }[];
    capabilities: string[];
    needsMfa: boolean;
    canManageGrants: boolean;
};

/**
 * Staff health entry point. Teachers see only the actionable alerts of
 * their own classes; health staff (explicit grant + 2FA) can open any
 * student's record and review emergency accesses.
 */
export default function HealthIndex({
    alerts,
    students,
    reviewQueue,
    needsMfa,
    canManageGrants,
}: Props) {
    const [query, setQuery] = useState('');
    const filtered = useMemo(
        () =>
            students.filter((s) =>
                `${s.name} ${s.className ?? ''}`
                    .toLowerCase()
                    .includes(query.trim().toLowerCase()),
            ),
        [students, query],
    );

    return (
        <PortalLayout>
            <Head title="ჯანმრთელობა" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ჯანმრთელობა</h1>
                {canManageGrants && (
                    <Link
                        href="/portal/health/permissions"
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                    >
                        <KeyRound size={16} aria-hidden /> წვდომის უფლებები
                    </Link>
                )}
            </div>

            <div className="mb-6 flex gap-3 rounded-xl bg-[var(--brand-secondary)] p-4 text-sm ring-1 ring-slate-200">
                <ShieldCheck className="shrink-0" aria-hidden />
                <p>
                    დაცული სამედიცინო ინფორმაცია. ყოველი ნახვა და ცვლილება
                    აღირიცხება. მასწავლებლები ხედავენ მხოლოდ მოქმედებისთვის
                    საჭირო გაფრთხილებას.
                </p>
            </div>

            {needsMfa && (
                <p
                    role="alert"
                    className="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    ჯანმრთელობის ჩანაწერზე სრული წვდომისთვის ჩართეთ ორფაქტორიანი
                    ავთენტიფიკაცია (
                    <Link href="/settings/security" className="underline">
                        უსაფრთხოების პარამეტრები
                    </Link>
                    ).
                </p>
            )}

            {reviewQueue.length > 0 && (
                <section className="mb-8" aria-labelledby="review-queue">
                    <h2
                        id="review-queue"
                        className="mb-3 flex items-center gap-2 text-lg"
                    >
                        <ShieldAlert size={20} aria-hidden /> გადაუდებელი
                        წვდომები განსახილველად
                    </h2>
                    <ul className="space-y-3">
                        {reviewQueue.map((item) => (
                            <li
                                key={item.id}
                                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            >
                                <p className="font-medium">
                                    {item.userName} → {item.studentName}
                                </p>
                                <p className="text-sm text-slate-500">
                                    {item.createdAt
                                        ? formatDateTime(item.createdAt)
                                        : ''}{' '}
                                    · ვადა {formatDateTime(item.expiresAt)}
                                </p>
                                <p className="mt-2 text-sm">
                                    მიზეზი: {item.reason}
                                </p>
                                <Button
                                    className="mt-3"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/portal/health/emergency-accesses/${item.id}/review`,
                                            {},
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    toast.success(
                                                        'განხილულად მოინიშნა.',
                                                    ),
                                                onError: (errors) =>
                                                    toast.error(
                                                        Object.values(
                                                            errors,
                                                        )[0] ?? 'ვერ შესრულდა.',
                                                    ),
                                            },
                                        )
                                    }
                                >
                                    განხილულია
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section className="mb-8" aria-labelledby="class-alerts">
                <h2 id="class-alerts" className="mb-3 text-lg">
                    ჩემი კლასების გაფრთხილებები
                </h2>
                {alerts.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                        თქვენს კლასებში მოქმედებისთვის საჭირო გაფრთხილება არ
                        არის.
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {alerts.map((student) => (
                            <li
                                key={student.studentId}
                                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            >
                                <Link
                                    href={`/portal/students/${student.studentId}/health`}
                                    className="font-medium hover:underline"
                                >
                                    {student.studentName}
                                </Link>
                                <span className="ml-2 text-sm text-slate-500">
                                    {student.className}
                                </span>
                                <ul className="mt-2 space-y-2">
                                    {student.alerts.map((alert, index) => (
                                        <li
                                            key={index}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <HeartPulse
                                                size={16}
                                                className="mt-0.5 shrink-0 text-[#c84925]"
                                                aria-hidden
                                            />
                                            <span>
                                                {alert.text}{' '}
                                                <SeverityBadge
                                                    severity={alert.severity}
                                                />
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {students.length > 0 && (
                <section aria-labelledby="all-students">
                    <h2 id="all-students" className="mb-3 text-lg">
                        მოსწავლეები
                    </h2>
                    <label htmlFor="health-search" className="sr-only">
                        მოსწავლის ძებნა
                    </label>
                    <input
                        id="health-search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="სახელი ან კლასი"
                        className="mb-3 h-11 w-full rounded-md border border-slate-300 px-3 text-sm sm:max-w-sm"
                    />
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {filtered.map((s) => (
                            <li key={s.id}>
                                <Link
                                    href={`/portal/students/${s.id}/health`}
                                    className="flex min-h-11 items-center justify-between gap-2 px-4 py-2 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                                >
                                    <span>{s.name}</span>
                                    <span className="text-sm text-slate-500">
                                        {s.className}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PortalLayout>
    );
}
