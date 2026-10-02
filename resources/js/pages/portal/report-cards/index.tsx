import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CheckCircle2, Circle, ScrollText } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { firstError } from '@/lib/assessment';

type QueueRow = {
    id: number;
    studentName: string;
    className: string;
    periodName: string;
    status: string;
    statusLabel: string;
};

type Props = {
    management: {
        classes: { id: number; name: string }[];
        periods: { id: number; name: string; isClosed: boolean }[];
        selectedClassId: number | null;
        selectedPeriodId: number | null;
        cards: {
            id: number;
            studentName: string;
            status: string;
            statusLabel: string;
            revision: number;
            subjectsCount: number;
            completedSubjectsCount: number;
        }[];
    } | null;
    myLines: {
        reportCardId: number;
        studentName: string;
        className: string;
        periodName: string;
        subject: string;
        isComplete: boolean;
    }[];
    homeroomQueue: QueueRow[];
    directorQueue: QueueRow[];
};

const selectClass =
    'mt-1 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

function Queue({ title, rows }: { title: string; rows: QueueRow[] }) {
    if (rows.length === 0) return null;

    return (
        <section className="mb-8" aria-label={title}>
            <h2 className="mb-3 text-lg">{title}</h2>
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                {rows.map((row) => (
                    <li key={row.id}>
                        <Link
                            href={`/portal/report-cards/${row.id}`}
                            className="flex min-h-11 flex-col gap-1 p-4 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span className="font-medium">
                                {row.studentName}
                            </span>
                            <span className="text-sm text-slate-500">
                                {row.className} · {row.periodName} ·{' '}
                                {row.statusLabel}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Report card worklist: each role sees its own step — management the class
 * overview and generation, teachers their unfinished subject lines,
 * homeroom teachers and the director their review queues.
 */
export default function ReportCardsIndex({
    management,
    myLines,
    homeroomQueue,
    directorQueue,
}: Props) {
    const [sending, setSending] = useState(false);
    const selectedPeriod = management?.periods.find(
        (p) => p.id === management.selectedPeriodId,
    );

    const filter = (classId: number | null, periodId: number | null) =>
        router.get(
            '/portal/report-cards',
            { class: classId ?? undefined, period: periodId ?? undefined },
            { preserveState: false },
        );

    const generate = (event: FormEvent) => {
        event.preventDefault();
        if (!management) return;
        setSending(true);
        router.post(
            '/portal/report-cards/generate',
            {
                school_class_id: management.selectedClassId,
                assessment_period_id: management.selectedPeriodId,
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('ტაბელები მომზადდა.'),
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ შეიქმნა.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const nothing =
        !management &&
        myLines.length === 0 &&
        homeroomQueue.length === 0 &&
        directorQueue.length === 0;

    return (
        <PortalLayout>
            <Head title="ტაბელები" />
            <h1 className="mb-6 text-2xl">ტაბელები</h1>

            <Queue title="დირექტორის რიგი" rows={directorQueue} />
            <Queue title="დამრიგებლის განხილვა" rows={homeroomQueue} />

            {myLines.length > 0 && (
                <section className="mb-8" aria-labelledby="my-lines">
                    <h2 id="my-lines" className="mb-3 text-lg">
                        ჩემი საგნები ტაბელებში
                    </h2>
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {myLines.map((line) => (
                            <li key={`${line.reportCardId}-${line.subject}`}>
                                <Link
                                    href={`/portal/report-cards/${line.reportCardId}`}
                                    className="flex min-h-11 items-center gap-3 p-4 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                                >
                                    {line.isComplete ? (
                                        <CheckCircle2
                                            size={18}
                                            className="shrink-0 text-emerald-600"
                                            aria-label="შევსებულია"
                                        />
                                    ) : (
                                        <Circle
                                            size={18}
                                            className="shrink-0 text-slate-400"
                                            aria-label="შესავსებია"
                                        />
                                    )}
                                    <span className="min-w-0">
                                        <span className="block font-medium">
                                            {line.studentName}
                                        </span>
                                        <span className="block text-sm text-slate-500">
                                            {line.subject} · {line.className} ·{' '}
                                            {line.periodName}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {management && (
                <section aria-labelledby="class-overview">
                    <h2 id="class-overview" className="mb-3 text-lg">
                        კლასის ტაბელები
                    </h2>
                    {management.classes.length === 0 ||
                    management.periods.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                            საჭიროა კლასი („სასწავლო წლები“) და შეფასების
                            პერიოდი („შეფასების წესები“).
                        </p>
                    ) : (
                        <>
                            <form
                                onSubmit={generate}
                                className="mb-4 grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            >
                                <label className="block text-sm font-medium">
                                    კლასი
                                    <select
                                        className={selectClass}
                                        value={management.selectedClassId ?? ''}
                                        onChange={(e) =>
                                            filter(
                                                Number(e.target.value),
                                                management.selectedPeriodId,
                                            )
                                        }
                                    >
                                        {management.classes.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="block text-sm font-medium">
                                    პერიოდი
                                    <select
                                        className={selectClass}
                                        value={
                                            management.selectedPeriodId ?? ''
                                        }
                                        onChange={(e) =>
                                            filter(
                                                management.selectedClassId,
                                                Number(e.target.value),
                                            )
                                        }
                                    >
                                        {management.periods.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                                {p.isClosed ? '' : ' (ღია)'}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <Button
                                    type="submit"
                                    disabled={
                                        sending || !selectedPeriod?.isClosed
                                    }
                                >
                                    ტაბელების მომზადება
                                </Button>
                                {selectedPeriod && !selectedPeriod.isClosed && (
                                    <p className="text-sm text-amber-800 sm:col-span-3">
                                        ტაბელი მზადდება მხოლოდ დახურული
                                        პერიოდისთვის — ჯერ დახურეთ პერიოდი
                                        „შეფასების წესებში“.
                                    </p>
                                )}
                            </form>

                            {management.cards.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                                    <ScrollText className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                                    <p className="font-medium">
                                        ამ კლასისა და პერიოდისთვის ტაბელი ჯერ არ
                                        მომზადებულა.
                                    </p>
                                </div>
                            ) : (
                                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                                    {management.cards.map((card) => (
                                        <li key={card.id}>
                                            <Link
                                                href={`/portal/report-cards/${card.id}`}
                                                className="flex min-h-11 flex-col gap-1 p-4 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <span className="font-medium">
                                                    {card.studentName}
                                                </span>
                                                <span className="text-sm text-slate-500">
                                                    {card.statusLabel} ·{' '}
                                                    {
                                                        card.completedSubjectsCount
                                                    }
                                                    /{card.subjectsCount} საგანი
                                                    {card.revision > 1
                                                        ? ` · რევიზია ${card.revision}`
                                                        : ''}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </section>
            )}

            {nothing && (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <ScrollText className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        ამჟამად თქვენთვის ტაბელზე სამუშაო არ არის.
                    </p>
                </div>
            )}
        </PortalLayout>
    );
}
