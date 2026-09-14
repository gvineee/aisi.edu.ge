import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type StageOption = { value: string; label: string };

type AppointmentRow = {
    id: number;
    scheduledAt: string;
    notes: string | null;
    createdByName: string | null;
};

type DecisionInfo = {
    id: number;
    decision: string;
    decidedAt: string;
    notes: string | null;
    decidedByName: string | null;
};

type LeadDetail = {
    id: number;
    guardianName: string;
    contactMethod: string;
    contactValue: string;
    desiredGrade: string | null;
    preferredDate: string | null;
    stage: string;
    createdAt: string | null;
    appointments: AppointmentRow[];
    decision: DecisionInfo | null;
};

type Props = {
    lead: LeadDetail;
    stages: StageOption[];
    nextStages: StageOption[];
    decisionOptions: StageOption[];
};

const CONTACT_METHOD_LABELS: Record<string, string> = {
    email: 'ელფოსტა',
    phone: 'ტელეფონი',
};

/**
 * One lead's pipeline detail: current stage + forward-only advance control,
 * appointment history + scheduling form, and the final decision (once
 * recorded, the decision form disappears — a lead is decided exactly once).
 */
export default function AdmissionsPipelineShow({
    lead,
    stages,
    nextStages,
    decisionOptions,
}: Props) {
    const [advancing, setAdvancing] = useState(false);
    const [schedulingVisit, setSchedulingVisit] = useState(false);
    const [decidingCase, setDecidingCase] = useState(false);

    const stageLabel = (value: string) =>
        stages.find((s) => s.value === value)?.label ?? value;

    const advanceStage = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setAdvancing(true);

        router.post(`/portal/admissions-pipeline/${lead.id}/advance-stage`, form, {
            onSuccess: () => toast.success('ეტაპი განახლდა.'),
            onError: () => toast.error('ეტაპის შეცვლა ვერ მოხერხდა.'),
            onFinish: () => setAdvancing(false),
        });
    };

    const scheduleAppointment = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSchedulingVisit(true);

        router.post(`/portal/admissions-pipeline/${lead.id}/appointments`, form, {
            onSuccess: () => {
                toast.success('ვიზიტი დაინიშნა.');
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSchedulingVisit(false),
        });
    };

    const recordDecision = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setDecidingCase(true);

        router.post(`/portal/admissions-pipeline/${lead.id}/decision`, form, {
            onSuccess: () => toast.success('გადაწყვეტილება დაფიქსირდა.'),
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setDecidingCase(false),
        });
    };

    return (
        <PortalLayout>
            <Head title={`განაცხადი — ${lead.guardianName}`} />

            <Link
                href="/portal/admissions-pipeline"
                className="mb-4 inline-flex min-h-11 items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
            >
                <ArrowLeft size={16} /> მიღების პროცესი
            </Link>

            <div className="mb-8 rounded-xl border border-slate-200 bg-white p-6">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl">{lead.guardianName}</h1>
                    <span className="inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">
                        {stageLabel(lead.stage)}
                    </span>
                </div>
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-slate-500">კონტაქტი</dt>
                        <dd>
                            {CONTACT_METHOD_LABELS[lead.contactMethod] ??
                                lead.contactMethod}
                            : {lead.contactValue}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">სასურველი კლასი</dt>
                        <dd>{lead.desiredGrade ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">სასურველი თარიღი</dt>
                        <dd>{lead.preferredDate ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">შემოსული</dt>
                        <dd>
                            {lead.createdAt
                                ? new Date(lead.createdAt).toLocaleString(
                                      'ka-GE',
                                  )
                                : '—'}
                        </dd>
                    </div>
                </dl>
            </div>

            {nextStages.length > 0 && (
                <section className="mb-8 rounded-xl border border-slate-200 bg-white p-6">
                    <h2 className="mb-3 text-lg">ეტაპის წინსვლა</h2>
                    <form
                        onSubmit={advanceStage}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <select
                            name="stage"
                            required
                            className="h-9 min-h-11 rounded-md border border-slate-300 px-3 text-sm"
                            aria-label="შემდეგი ეტაპი"
                        >
                            {nextStages.map((stage) => (
                                <option key={stage.value} value={stage.value}>
                                    {stage.label}
                                </option>
                            ))}
                        </select>
                        <Button type="submit" disabled={advancing}>
                            გადაყვანა
                        </Button>
                    </form>
                </section>
            )}

            <section className="mb-8 rounded-xl border border-slate-200 bg-white p-6">
                <h2 className="mb-3 text-lg">ვიზიტები</h2>
                {lead.appointments.length === 0 ? (
                    <p className="mb-4 text-sm text-slate-500">
                        ვიზიტი ჯერ არ დაგეგმილა.
                    </p>
                ) : (
                    <ul className="mb-4 divide-y divide-slate-100">
                        {lead.appointments.map((appointment) => (
                            <li key={appointment.id} className="py-2 text-sm">
                                <p className="font-medium">
                                    {new Date(
                                        appointment.scheduledAt,
                                    ).toLocaleString('ka-GE')}
                                </p>
                                {appointment.notes && (
                                    <p className="text-slate-500">
                                        {appointment.notes}
                                    </p>
                                )}
                                {appointment.createdByName && (
                                    <p className="text-xs text-slate-400">
                                        დანიშნა: {appointment.createdByName}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    onSubmit={scheduleAppointment}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            თარიღი და დრო
                        </label>
                        <Input
                            name="scheduled_at"
                            type="datetime-local"
                            required
                        />
                    </div>
                    <div className="min-w-[200px] flex-1">
                        <label className="mb-1 block text-sm font-medium">
                            შენიშვნა (არასავალდებულო)
                        </label>
                        <Input name="notes" />
                    </div>
                    <Button type="submit" disabled={schedulingVisit}>
                        ვიზიტის დაგეგმვა
                    </Button>
                </form>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-6">
                <h2 className="mb-3 text-lg">გადაწყვეტილება</h2>
                {lead.decision ? (
                    <div className="text-sm">
                        <p className="font-medium">
                            {
                                decisionOptions.find(
                                    (option) =>
                                        option.value === lead.decision?.decision,
                                )?.label
                            }
                        </p>
                        <p className="text-slate-500">
                            {new Date(
                                lead.decision.decidedAt,
                            ).toLocaleString('ka-GE')}
                            {lead.decision.decidedByName &&
                                ` · ${lead.decision.decidedByName}`}
                        </p>
                        {lead.decision.notes && (
                            <p className="mt-2 text-slate-600">
                                {lead.decision.notes}
                            </p>
                        )}
                    </div>
                ) : (
                    <form
                        onSubmit={recordDecision}
                        className="flex flex-wrap items-end gap-3"
                    >
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                გადაწყვეტილება
                            </label>
                            <select
                                name="decision"
                                required
                                className="h-9 min-h-11 rounded-md border border-slate-300 px-3 text-sm"
                            >
                                <option value="">აირჩიეთ...</option>
                                {decisionOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="min-w-[200px] flex-1">
                            <label className="mb-1 block text-sm font-medium">
                                შენიშვნა (არასავალდებულო)
                            </label>
                            <Input name="notes" />
                        </div>
                        <Button type="submit" disabled={decidingCase}>
                            დაფიქსირება
                        </Button>
                    </form>
                )}
            </section>
        </PortalLayout>
    );
}
