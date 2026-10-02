import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ChartLine, ScrollText } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import AssessmentStatusBadge from '@/components/portal/assessment-status-badge';
import { formatDay, formatPercent, formatScore } from '@/lib/assessment';

type AssessmentRow = {
    id: number;
    periodId: number;
    title: string;
    assessedOn: string;
    categoryName: string | null;
    status: string;
    maxScore: string;
    rawScore: string | null;
    normalizedPercent: string | null;
    finalGrade: string | null;
    comment: string | null;
};

type SubjectGroup = {
    subject: string;
    assessments: AssessmentRow[];
};

type Props = {
    student: { id: number; name: string };
    viewerSeesDrafts: boolean;
    periods: {
        id: number;
        name: string;
        startsOn: string;
        endsOn: string;
        isClosed: boolean;
    }[];
    subjects: SubjectGroup[];
    competencies: {
        competency: string;
        level: number;
        label: string;
        descriptor: string | null;
        observedOn: string;
        note: string | null;
        shared: boolean;
    }[];
};

/**
 * One student's results by subject. Shows only what exists — no forecast,
 * no invented trend — and states the period and how many scored
 * assessments each summary is based on (CLAUDE-PLATFORM-MODULES §6).
 */
export default function ProgressShow({
    student,
    viewerSeesDrafts,
    periods,
    subjects,
    competencies,
}: Props) {
    const [periodId, setPeriodId] = useState<number | 'all'>('all');
    const activePeriod = periods.find((p) => p.id === periodId);

    const filtered = subjects
        .map((group) => {
            const rows =
                periodId === 'all'
                    ? group.assessments
                    : group.assessments.filter((a) => a.periodId === periodId);
            const scored = rows.filter((r) => r.normalizedPercent !== null);
            const average =
                scored.length === 0
                    ? null
                    : (
                          scored.reduce(
                              (sum, r) => sum + Number(r.normalizedPercent),
                              0,
                          ) / scored.length
                      ).toFixed(2);

            return {
                ...group,
                assessments: rows,
                scored: scored.length,
                average,
            };
        })
        .filter((group) => group.assessments.length > 0);

    return (
        <PortalLayout>
            <Head title={`${student.name} — პროგრესი`} />

            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl">{student.name} — პროგრესი</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        {viewerSeesDrafts
                            ? 'ჩანს გამოქვეყნებული და თქვენთვის ხელმისაწვდომი დრაფტი შეფასებები.'
                            : 'ჩანს მხოლოდ მასწავლებლის მიერ გამოქვეყნებული შეფასებები.'}
                    </p>
                    <Link
                        href={`/portal/students/${student.id}/report-cards`}
                        className="mt-2 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-[var(--brand-primary)] underline-offset-4 hover:underline"
                    >
                        <ScrollText size={16} aria-hidden /> ტაბელები
                    </Link>
                </div>
                {periods.length > 0 && (
                    <label className="text-sm font-medium">
                        პერიოდი
                        <select
                            className="mt-1 block h-11 rounded-md border border-slate-300 bg-white px-3 text-sm"
                            value={periodId}
                            onChange={(e) =>
                                setPeriodId(
                                    e.target.value === 'all'
                                        ? 'all'
                                        : Number(e.target.value),
                                )
                            }
                        >
                            <option value="all">ყველა პერიოდი</option>
                            {periods.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {filtered.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <ChartLine className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        შეფასებები ჯერ არ არის გამოქვეყნებული.
                    </p>
                </div>
            ) : (
                <div className="space-y-6">
                    {filtered.map((group) => (
                        <section
                            key={group.subject}
                            className="rounded-xl border border-slate-200 bg-white"
                            aria-label={group.subject}
                        >
                            <header className="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 p-4">
                                <h2 className="text-lg">{group.subject}</h2>
                                <p className="text-sm text-slate-600">
                                    {group.average === null
                                        ? 'ქულა ჯერ არ არის'
                                        : `საშუალო ${formatPercent(group.average)} · ${group.scored} შეფასებიდან`}
                                    {' · '}
                                    {activePeriod
                                        ? `${formatDay(activePeriod.startsOn)} – ${formatDay(activePeriod.endsOn)}`
                                        : 'ყველა პერიოდი'}
                                </p>
                            </header>
                            <ul className="divide-y divide-slate-100">
                                {group.assessments.map((a) => (
                                    <li
                                        key={a.id}
                                        className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium break-words">
                                                {a.title}
                                            </p>
                                            <p className="text-sm text-slate-500">
                                                {formatDay(a.assessedOn)}
                                                {a.categoryName
                                                    ? ` · ${a.categoryName}`
                                                    : ''}
                                            </p>
                                            {a.comment && (
                                                <p className="mt-1 text-sm text-slate-700">
                                                    {a.comment}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3">
                                            {a.status !== 'published' && (
                                                <AssessmentStatusBadge
                                                    status={a.status}
                                                />
                                            )}
                                            {a.rawScore === null ? (
                                                <span className="text-sm text-slate-500">
                                                    ქულა არ არის
                                                </span>
                                            ) : (
                                                <span className="text-right">
                                                    <span className="block font-semibold">
                                                        {formatScore(
                                                            a.rawScore,
                                                        )}
                                                        /
                                                        {formatScore(
                                                            a.maxScore,
                                                        )}
                                                        {a.finalGrade
                                                            ? ` · ${a.finalGrade}`
                                                            : ''}
                                                    </span>
                                                    <span className="text-xs text-slate-500">
                                                        {formatPercent(
                                                            a.normalizedPercent,
                                                        )}
                                                    </span>
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                    <p className="text-xs text-slate-500">
                        საშუალო არის არაწონიანი და მხოლოდ ინფორმაციული — სკოლის
                        დამტკიცებული წონის წესი ჯერ არ არის გამოყენებული.
                    </p>
                </div>
            )}
            {competencies.length > 0 && (
                <section
                    className="mt-8"
                    aria-labelledby="competencies-heading"
                >
                    <h2 id="competencies-heading" className="mb-3 text-lg">
                        კომპეტენციები
                    </h2>
                    <ul className="grid gap-3 md:grid-cols-2">
                        {competencies.map((c) => (
                            <li
                                key={c.competency}
                                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            >
                                <p className="font-medium">{c.competency}</p>
                                <p className="text-sm">
                                    დონე {c.level}: <strong>{c.label}</strong>
                                    {!c.shared && (
                                        <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                            ოჯახს არ უჩანს
                                        </span>
                                    )}
                                </p>
                                {c.descriptor && (
                                    <p className="mt-1 text-sm text-slate-600">
                                        {c.descriptor}
                                    </p>
                                )}
                                {c.note && (
                                    <p className="mt-1 text-sm">{c.note}</p>
                                )}
                                <p className="mt-1 text-xs text-slate-500">
                                    {formatDay(c.observedOn)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PortalLayout>
    );
}
