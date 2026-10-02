import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import {
    Clock,
    FileText,
    HeartPulse,
    History,
    Phone,
    ShieldAlert,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SeverityBadge from '@/components/portal/severity-badge';
import { firstError, formatDateTime } from '@/lib/assessment';

type Condition = {
    id: number;
    name: string;
    severity: string;
    actionPlan: string | null;
    showAlertToStaff: boolean;
    staffAlert: string | null;
};

type Record = {
    familyDoctor: string | null;
    notes: string | null;
    conditions: Condition[];
    medications: { id: number; name: string; instructions: string | null }[];
    contacts: {
        id: number;
        name: string;
        relationship: string | null;
        phone: string;
        priority: number;
    }[];
    carePlans: {
        id: number;
        title: string;
        uploadedAt: string | null;
        downloadUrl: string;
    }[];
    incidents: {
        id: number;
        occurredAt: string;
        summary: string;
        actionTaken: string | null;
        guardianNotifiedAt: string | null;
    }[];
};

type Props = {
    student: { id: number; name: string; className: string | null };
    basis: 'full' | 'guardian' | 'emergency' | 'alerts_only' | 'none';
    alerts: { severity: string; text: string | null }[];
    record: Record | null;
    emergencyExpiresAt: string | null;
    needsMfa: boolean;
    can: {
        manage: boolean;
        createIncident: boolean;
        viewAudit: boolean;
        requestEmergency: boolean;
    };
};

const textareaClass =
    'min-h-20 w-full rounded-md border border-slate-300 p-3 text-sm';
const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl bg-white p-5 ring-1 ring-slate-200">
            <h2 className="mb-3 text-lg">{title}</h2>
            {children}
        </section>
    );
}

function Field({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-sm font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}

/**
 * One student's health record. The server decides the access basis; this
 * page renders exactly what that basis allows and nothing more.
 */
export default function HealthShow({
    student,
    basis,
    alerts,
    record,
    emergencyExpiresAt,
    needsMfa,
    can,
}: Props) {
    const base = `/portal/students/${student.id}/health`;
    const [openForm, setOpenForm] = useState<string | null>(null);
    const [sending, setSending] = useState(false);

    const submit =
        (url: string, success: string, multipart = false) =>
        (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const form = event.currentTarget;
            setSending(true);
            router.post(url, new FormData(form), {
                preserveScroll: true,
                forceFormData: multipart,
                onSuccess: () => {
                    toast.success(success);
                    form.reset();
                    setOpenForm(null);
                },
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'შეამოწმეთ ველები.'),
                onFinish: () => setSending(false),
            });
        };

    const remove = (type: string, id: number) =>
        router.post(
            `${base}/${type}/${id}/remove`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('წაიშალა.'),
            },
        );

    const toggle = (key: string) =>
        setOpenForm((current) => (current === key ? null : key));

    return (
        <PortalLayout>
            <Head title={`${student.name} — ჯანმრთელობა`} />

            <div className="mb-4">
                <h1 className="text-2xl">{student.name} — ჯანმრთელობა</h1>
                {student.className && (
                    <p className="text-sm text-slate-500">
                        {student.className}
                    </p>
                )}
            </div>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3 rounded-xl bg-[var(--brand-secondary)] p-4 text-sm ring-1 ring-slate-200">
                <p className="flex gap-3">
                    <ShieldCheck className="shrink-0" aria-hidden />
                    <span>
                        <strong className="block">
                            დაცული სამედიცინო ჩანაწერი
                        </strong>
                        ხედავენ მხოლოდ მშობელი და უფლებამოსილი თანამშრომლები.
                        ყველა ნახვა აღირიცხება.
                    </span>
                </p>
                {can.viewAudit && (
                    <Link
                        href={`${base}/access-log`}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-3 ring-1 ring-slate-200 hover:bg-slate-50"
                    >
                        <History size={16} aria-hidden /> წვდომის ისტორია
                    </Link>
                )}
            </div>

            {basis === 'emergency' && emergencyExpiresAt && (
                <p
                    role="status"
                    className="mb-6 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    <Clock size={16} aria-hidden /> გადაუდებელი წვდომა აქტიურია{' '}
                    {formatDateTime(emergencyExpiresAt)}-მდე. ის განსახილველად
                    გადაეცემა.
                </p>
            )}

            {needsMfa && (
                <p
                    role="alert"
                    className="mb-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    სრული ჩანაწერის სანახავად ჩართეთ ორფაქტორიანი ავთენტიფიკაცია
                    (
                    <Link href="/settings/security" className="underline">
                        უსაფრთხოება
                    </Link>
                    ).
                </p>
            )}

            {alerts.length > 0 && (
                <section
                    className="mb-6"
                    aria-label="მნიშვნელოვანი გაფრთხილებები"
                >
                    <ul className="space-y-2">
                        {alerts.map((alert, index) => (
                            <li
                                key={index}
                                className="flex items-start gap-3 rounded-xl bg-red-50 p-4 text-red-950"
                            >
                                <HeartPulse
                                    className="mt-0.5 shrink-0"
                                    aria-hidden
                                />
                                <span>
                                    <strong className="block">
                                        {alert.text}
                                    </strong>
                                    <SeverityBadge severity={alert.severity} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {basis === 'alerts_only' && alerts.length === 0 && (
                <p className="mb-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                    მასწავლებლისთვის განკუთვნილი გაფრთხილება არ არის.
                </p>
            )}

            {can.requestEmergency && (
                <section className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
                    {openForm === 'emergency' ? (
                        <form
                            onSubmit={submit(
                                `${base}/emergency-access`,
                                'გადაუდებელი წვდომა გაიხსნა.',
                            )}
                            className="space-y-3"
                        >
                            <Field
                                id="emergency-reason"
                                label="რატომ გჭირდებათ სრული ჩანაწერი ახლა? (სავალდებულო, განიხილება)"
                            >
                                <textarea
                                    id="emergency-reason"
                                    name="reason"
                                    required
                                    minLength={10}
                                    maxLength={1000}
                                    className={textareaClass}
                                />
                            </Field>
                            <div className="flex gap-2">
                                <Button type="submit" disabled={sending}>
                                    წვდომის გახსნა
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpenForm(null)}
                                >
                                    გაუქმება
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="flex items-center gap-2 text-sm">
                                <ShieldAlert size={18} aria-hidden /> სრული
                                ჩანაწერი თქვენთვის დახურულია.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => toggle('emergency')}
                            >
                                გადაუდებელი წვდომა
                            </Button>
                        </div>
                    )}
                </section>
            )}

            {can.createIncident && !record && (
                <IncidentForm
                    base={base}
                    open={openForm === 'incident'}
                    onToggle={() => toggle('incident')}
                    onSubmit={submit(`${base}/incidents`, 'ინციდენტი ჩაიწერა.')}
                    sending={sending}
                />
            )}

            {record && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card title="მნიშვნელოვანი ინფორმაცია">
                        {record.conditions.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                მდგომარეობა დამატებული არ არის.
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {record.conditions.map((c) => (
                                    <li
                                        key={c.id}
                                        className="rounded-lg bg-slate-50 p-3 text-sm"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <strong>{c.name}</strong>
                                            {can.manage && (
                                                <RemoveButton
                                                    label={`წაშლა: ${c.name}`}
                                                    onClick={() =>
                                                        remove(
                                                            'conditions',
                                                            c.id,
                                                        )
                                                    }
                                                />
                                            )}
                                        </div>
                                        <SeverityBadge severity={c.severity} />
                                        {c.actionPlan && (
                                            <p className="mt-2 whitespace-pre-wrap">
                                                <span className="font-medium">
                                                    სამოქმედო გეგმა:{' '}
                                                </span>
                                                {c.actionPlan}
                                            </p>
                                        )}
                                        {c.showAlertToStaff && (
                                            <p className="mt-1 text-slate-600">
                                                მასწავლებლები ხედავენ: „
                                                {c.staffAlert}“
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {can.manage && (
                            <div className="mt-3">
                                {openForm === 'condition' ? (
                                    <form
                                        onSubmit={submit(
                                            `${base}/conditions`,
                                            'დაემატა.',
                                        )}
                                        className="space-y-3"
                                    >
                                        <Field id="c-name" label="მდგომარეობა">
                                            <Input
                                                id="c-name"
                                                name="name"
                                                required
                                                maxLength={255}
                                            />
                                        </Field>
                                        <Field
                                            id="c-severity"
                                            label="მნიშვნელობა"
                                        >
                                            <select
                                                id="c-severity"
                                                name="severity"
                                                className={selectClass}
                                                defaultValue="medium"
                                            >
                                                <option value="high">
                                                    მაღალი
                                                </option>
                                                <option value="medium">
                                                    საშუალო
                                                </option>
                                                <option value="low">
                                                    დაბალი
                                                </option>
                                            </select>
                                        </Field>
                                        <Field
                                            id="c-plan"
                                            label="სამოქმედო გეგმა (როგორც ოჯახმა/ექიმმა მიაწოდა)"
                                        >
                                            <textarea
                                                id="c-plan"
                                                name="action_plan"
                                                maxLength={5000}
                                                className={textareaClass}
                                            />
                                        </Field>
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="show_alert_to_staff"
                                                value="1"
                                                className="h-4 w-4"
                                            />
                                            მასწავლებლებს ეჩვენოთ მოკლე
                                            გაფრთხილება
                                        </label>
                                        <Field
                                            id="c-alert"
                                            label="გაფრთხილების ტექსტი მასწავლებლისთვის"
                                        >
                                            <Input
                                                id="c-alert"
                                                name="staff_alert"
                                                maxLength={500}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={sending}
                                        >
                                            დამატება
                                        </Button>
                                    </form>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => toggle('condition')}
                                    >
                                        მდგომარეობის დამატება
                                    </Button>
                                )}
                            </div>
                        )}
                    </Card>

                    <Card title="გადაუდებელი კონტაქტები">
                        {record.contacts.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                კონტაქტი დამატებული არ არის.
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {record.contacts.map((c) => (
                                    <li
                                        key={c.id}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <span>
                                            <strong>{c.name}</strong>
                                            {c.relationship
                                                ? ` · ${c.relationship}`
                                                : ''}
                                            <a
                                                href={`tel:${c.phone}`}
                                                className="ml-2 inline-flex min-h-11 items-center gap-1 underline"
                                            >
                                                <Phone size={14} aria-hidden />{' '}
                                                {c.phone}
                                            </a>
                                        </span>
                                        {can.manage && (
                                            <RemoveButton
                                                label={`წაშლა: ${c.name}`}
                                                onClick={() =>
                                                    remove('contacts', c.id)
                                                }
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="mt-3 text-sm">
                            <span className="text-slate-500">
                                ოჯახის ექიმი:{' '}
                            </span>
                            {record.familyDoctor ??
                                'ინფორმაცია დამატებული არ არის'}
                        </p>
                        {record.notes && (
                            <p className="mt-2 text-sm whitespace-pre-wrap">
                                {record.notes}
                            </p>
                        )}
                        {can.manage && (
                            <div className="mt-3 space-y-3">
                                {openForm === 'contact' ? (
                                    <form
                                        onSubmit={submit(
                                            `${base}/contacts`,
                                            'დაემატა.',
                                        )}
                                        className="space-y-3"
                                    >
                                        <Field id="ct-name" label="სახელი">
                                            <Input
                                                id="ct-name"
                                                name="name"
                                                required
                                                maxLength={255}
                                            />
                                        </Field>
                                        <Field id="ct-rel" label="ვინ არის">
                                            <Input
                                                id="ct-rel"
                                                name="relationship"
                                                maxLength={100}
                                            />
                                        </Field>
                                        <Field id="ct-phone" label="ტელეფონი">
                                            <Input
                                                id="ct-phone"
                                                name="phone"
                                                type="tel"
                                                required
                                                maxLength={40}
                                            />
                                        </Field>
                                        <Field
                                            id="ct-priority"
                                            label="რიგი (1 = პირველი)"
                                        >
                                            <Input
                                                id="ct-priority"
                                                name="priority"
                                                type="number"
                                                min={1}
                                                max={9}
                                                defaultValue={1}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={sending}
                                        >
                                            დამატება
                                        </Button>
                                    </form>
                                ) : openForm === 'profile' ? (
                                    <form
                                        onSubmit={submit(
                                            `${base}/profile`,
                                            'შენახულია.',
                                        )}
                                        className="space-y-3"
                                    >
                                        <Field
                                            id="p-doctor"
                                            label="ოჯახის ექიმი"
                                        >
                                            <Input
                                                id="p-doctor"
                                                name="family_doctor"
                                                maxLength={500}
                                                defaultValue={
                                                    record.familyDoctor ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field id="p-notes" label="შენიშვნები">
                                            <textarea
                                                id="p-notes"
                                                name="notes"
                                                maxLength={5000}
                                                className={textareaClass}
                                                defaultValue={
                                                    record.notes ?? ''
                                                }
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={sending}
                                        >
                                            შენახვა
                                        </Button>
                                    </form>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => toggle('contact')}
                                        >
                                            კონტაქტის დამატება
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => toggle('profile')}
                                        >
                                            ექიმი და შენიშვნები
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </Card>

                    <Card title="მედიკამენტები">
                        {record.medications.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                მედიკამენტი დამატებული არ არის.
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {record.medications.map((m) => (
                                    <li
                                        key={m.id}
                                        className="flex items-start justify-between gap-2"
                                    >
                                        <span>
                                            <strong>{m.name}</strong>
                                            {m.instructions && (
                                                <span className="block whitespace-pre-wrap text-slate-600">
                                                    {m.instructions}
                                                </span>
                                            )}
                                        </span>
                                        {can.manage && (
                                            <RemoveButton
                                                label={`წაშლა: ${m.name}`}
                                                onClick={() =>
                                                    remove('medications', m.id)
                                                }
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="mt-2 text-xs text-slate-500">
                            ინსტრუქცია ჩაწერილია ზუსტად ისე, როგორც ოჯახმა ან
                            ექიმმა მიაწოდა. სისტემა დოზას არ ითვლის და რჩევას არ
                            იძლევა.
                        </p>
                        {can.manage && (
                            <div className="mt-3">
                                {openForm === 'medication' ? (
                                    <form
                                        onSubmit={submit(
                                            `${base}/medications`,
                                            'დაემატა.',
                                        )}
                                        className="space-y-3"
                                    >
                                        <Field id="m-name" label="დასახელება">
                                            <Input
                                                id="m-name"
                                                name="name"
                                                required
                                                maxLength={255}
                                            />
                                        </Field>
                                        <Field
                                            id="m-instr"
                                            label="ინსტრუქცია (როგორც მიწოდებულია)"
                                        >
                                            <textarea
                                                id="m-instr"
                                                name="instructions"
                                                maxLength={2000}
                                                className={textareaClass}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={sending}
                                        >
                                            დამატება
                                        </Button>
                                    </form>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => toggle('medication')}
                                    >
                                        მედიკამენტის დამატება
                                    </Button>
                                )}
                            </div>
                        )}
                    </Card>

                    <Card title="სამოქმედო გეგმის დოკუმენტები">
                        {record.carePlans.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                დოკუმენტი არ არის.
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {record.carePlans.map((d) => (
                                    <li
                                        key={d.id}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <a
                                            href={d.downloadUrl}
                                            className="inline-flex min-h-11 items-center gap-2 underline"
                                        >
                                            <FileText size={16} aria-hidden />{' '}
                                            {d.title}
                                        </a>
                                        {can.manage && (
                                            <RemoveButton
                                                label={`წაშლა: ${d.title}`}
                                                onClick={() =>
                                                    remove('care-plans', d.id)
                                                }
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {can.manage && (
                            <div className="mt-3">
                                {openForm === 'care-plan' ? (
                                    <form
                                        onSubmit={submit(
                                            `${base}/care-plans`,
                                            'ატვირთულია.',
                                            true,
                                        )}
                                        className="space-y-3"
                                    >
                                        <Field id="cp-title" label="დასახელება">
                                            <Input
                                                id="cp-title"
                                                name="title"
                                                required
                                                maxLength={255}
                                            />
                                        </Field>
                                        <Field
                                            id="cp-file"
                                            label="ფაილი (PDF, PNG, JPEG — მაქს. 10MB)"
                                        >
                                            <input
                                                id="cp-file"
                                                name="file"
                                                type="file"
                                                required
                                                accept="application/pdf,image/png,image/jpeg"
                                                className="block min-h-11 text-sm"
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={sending}
                                        >
                                            ატვირთვა
                                        </Button>
                                    </form>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => toggle('care-plan')}
                                    >
                                        დოკუმენტის ატვირთვა
                                    </Button>
                                )}
                            </div>
                        )}
                    </Card>

                    <div className="lg:col-span-2">
                        <Card title="ინციდენტების ჟურნალი">
                            {record.incidents.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    ინციდენტი ჩაწერილი არ არის.
                                </p>
                            ) : (
                                <ol className="space-y-3 text-sm">
                                    {record.incidents.map((i) => (
                                        <li
                                            key={i.id}
                                            className="rounded-lg bg-slate-50 p-3"
                                        >
                                            <time className="text-slate-500">
                                                {formatDateTime(i.occurredAt)}
                                            </time>
                                            <strong className="block">
                                                {i.summary}
                                            </strong>
                                            {i.actionTaken && (
                                                <p className="whitespace-pre-wrap">
                                                    {i.actionTaken}
                                                </p>
                                            )}
                                            <p className="text-slate-600">
                                                {i.guardianNotifiedAt
                                                    ? `მშობელი ინფორმირებულია ${formatDateTime(i.guardianNotifiedAt)}-ზე.`
                                                    : 'მშობლის ინფორმირება მონიშნული არ არის.'}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            )}
                            {can.createIncident && (
                                <div className="mt-3">
                                    <IncidentForm
                                        base={base}
                                        open={openForm === 'incident'}
                                        onToggle={() => toggle('incident')}
                                        onSubmit={submit(
                                            `${base}/incidents`,
                                            'ინციდენტი ჩაიწერა.',
                                        )}
                                        sending={sending}
                                        inline
                                    />
                                </div>
                            )}
                        </Card>
                    </div>
                </div>
            )}

            {basis === 'guardian' && (
                <p className="mt-6 text-sm text-slate-600">
                    ცვლილებისთვის მიმართეთ სკოლის სამედიცინო პერსონალს.
                </p>
            )}
        </PortalLayout>
    );
}

function RemoveButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    const [confirming, setConfirming] = useState(false);

    return confirming ? (
        <span className="flex shrink-0 gap-1">
            <Button size="sm" variant="destructive" onClick={onClick}>
                წაშლა
            </Button>
            <Button
                size="sm"
                variant="outline"
                onClick={() => setConfirming(false)}
            >
                არა
            </Button>
        </span>
    ) : (
        <Button
            size="icon"
            variant="ghost"
            className="h-11 w-11 shrink-0"
            aria-label={label}
            onClick={() => setConfirming(true)}
        >
            <Trash2 size={16} />
        </Button>
    );
}

function IncidentForm({
    open,
    onToggle,
    onSubmit,
    sending,
    inline = false,
}: {
    base: string;
    open: boolean;
    onToggle: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    sending: boolean;
    inline?: boolean;
}) {
    if (!open) {
        return (
            <div className={inline ? '' : 'mb-6'}>
                <Button variant="outline" size="sm" onClick={onToggle}>
                    ინციდენტის ჩაწერა
                </Button>
            </div>
        );
    }

    return (
        <form
            onSubmit={onSubmit}
            className={`space-y-3 ${inline ? '' : 'mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200'}`}
        >
            <Field id="i-time" label="როდის მოხდა">
                <Input
                    id="i-time"
                    name="occurred_at"
                    type="datetime-local"
                    required
                />
            </Field>
            <Field id="i-summary" label="რა მოხდა">
                <textarea
                    id="i-summary"
                    name="summary"
                    required
                    maxLength={2000}
                    className={textareaClass}
                />
            </Field>
            <Field id="i-action" label="რა გაკეთდა">
                <textarea
                    id="i-action"
                    name="action_taken"
                    maxLength={2000}
                    className={textareaClass}
                />
            </Field>
            <Field id="i-notified" label="მშობელი ინფორმირებულია (დრო)">
                <Input
                    id="i-notified"
                    name="guardian_notified_at"
                    type="datetime-local"
                />
            </Field>
            <div className="flex gap-2">
                <Button type="submit" disabled={sending}>
                    ჩაწერა
                </Button>
                <Button type="button" variant="outline" onClick={onToggle}>
                    გაუქმება
                </Button>
            </div>
        </form>
    );
}
