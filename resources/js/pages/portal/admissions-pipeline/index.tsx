import { Head, Link, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type StageOption = { value: string; label: string };

type LeadRow = {
    id: number;
    guardianName: string;
    contactMethod: string;
    contactValue: string;
    desiredGrade: string | null;
    stage: string;
    appointmentsCount: number;
    createdAt: string | null;
};

type Props = {
    leads: LeadRow[];
    stages: StageOption[];
    filters: { stage: string };
};

const CONTACT_METHOD_LABELS: Record<string, string> = {
    email: 'ელფოსტა',
    phone: 'ტელეფონი',
};

function LeadCard({ lead }: { lead: LeadRow }) {
    return (
        <Link
            href={`/portal/admissions-pipeline/${lead.id}`}
            className="block rounded-lg border border-slate-200 bg-white p-3 text-sm hover:border-slate-300 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
        >
            <p className="font-medium">{lead.guardianName}</p>
            <p className="text-slate-500">
                {CONTACT_METHOD_LABELS[lead.contactMethod] ??
                    lead.contactMethod}
                : {lead.contactValue}
            </p>
            {lead.desiredGrade && (
                <p className="text-slate-500">კლასი: {lead.desiredGrade}</p>
            )}
            {lead.appointmentsCount > 0 && (
                <p className="mt-1 text-xs text-slate-400">
                    დაგეგმილი ვიზიტები: {lead.appointmentsCount}
                </p>
            )}
        </Link>
    );
}

/**
 * "მიღების პროცესი" — extends the Phase 1 public lead form into a real
 * pipeline (last audit: Admissions was 0% built beyond that bare contact
 * form). No stage filter selected: kanban-style columns, one per
 * AdmissionLead::STAGES value, in an isolated horizontal-scroll strip so the
 * page body itself never scrolls sideways on mobile. A stage selected:
 * a single filtered list instead.
 */
export default function AdmissionsPipelineIndex({
    leads,
    stages,
    filters,
}: Props) {
    const applyStageFilter = (stage: string) => {
        router.get(
            '/portal/admissions-pipeline',
            stage ? { stage } : {},
            { preserveState: true, replace: true },
        );
    };

    const leadsByStage = (stage: string) =>
        leads.filter((lead) => lead.stage === stage);

    return (
        <PortalLayout>
            <Head title="მიღების პროცესი" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">მიღების პროცესი</h1>
                <select
                    value={filters.stage}
                    onChange={(event) => applyStageFilter(event.target.value)}
                    className="h-9 rounded-md border border-slate-300 px-3 text-sm"
                    aria-label="ეტაპის მიხედვით გაფილტვრა"
                >
                    <option value="">ყველა ეტაპი (სვეტების ხედი)</option>
                    {stages.map((stage) => (
                        <option key={stage.value} value={stage.value}>
                            {stage.label}
                        </option>
                    ))}
                </select>
            </div>

            {leads.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <UserPlus className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">განაცხადი ვერ მოიძებნა.</p>
                </div>
            ) : filters.stage ? (
                <ul className="space-y-2">
                    {leads.map((lead) => (
                        <li key={lead.id}>
                            <LeadCard lead={lead} />
                        </li>
                    ))}
                </ul>
            ) : (
                <div className="-mx-6 overflow-x-auto px-6">
                    <div className="flex min-w-max gap-4">
                        {stages.map((stage) => {
                            const column = leadsByStage(stage.value);

                            return (
                                <div
                                    key={stage.value}
                                    className="w-72 shrink-0 rounded-xl border border-slate-200 bg-slate-50 p-3"
                                >
                                    <h2 className="mb-3 flex items-center justify-between text-sm font-semibold">
                                        <span>{stage.label}</span>
                                        <span className="rounded-full bg-white px-2 py-0.5 text-xs text-slate-500">
                                            {column.length}
                                        </span>
                                    </h2>
                                    <div className="space-y-2">
                                        {column.length === 0 ? (
                                            <p className="text-xs text-slate-400">
                                                ცარიელია
                                            </p>
                                        ) : (
                                            column.map((lead) => (
                                                <LeadCard
                                                    key={lead.id}
                                                    lead={lead}
                                                />
                                            ))
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </PortalLayout>
    );
}
