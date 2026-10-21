import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CheckCircle2, ScrollText } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDateTime, formatDay } from '@/lib/assessment';

export type MyAcknowledgement = {
    id: number;
    versionOrdinal: number | null;
    dueOn: string | null;
    note: string | null;
    fileReady: boolean;
} | null;

export type AcknowledgementRequest = {
    id: number;
    versionOrdinal: number;
    roles: string[];
    dueOn: string | null;
    note: string | null;
    requestedBy: string;
    createdAt: string | null;
    recipients: { name: string; acknowledgedAt: string | null }[];
} | null;

const options = (success: string, onDone?: () => void) => ({
    preserveScroll: true,
    onSuccess: () => {
        toast.success(success);
        onDone?.();
    },
    onError: (errors: Record<string, string>) =>
        toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
});

/**
 * Shown to a recipient: confirm having read this exact published version.
 */
export function MyAcknowledgementBanner({ ack }: { ack: MyAcknowledgement }) {
    if (ack === null) {
        return null;
    }

    return (
        <div
            role="status"
            className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-4 text-amber-950 ring-1 ring-amber-200"
        >
            <p className="flex items-start gap-2 text-sm">
                <ScrollText size={18} className="mt-0.5 shrink-0" aria-hidden />
                <span>
                    გთხოვენ, გაეცნოთ ამ დოკუმენტს (ვერსია{' '}
                    {ack.versionOrdinal ?? '—'})
                    {ack.dueOn && `, ${formatDay(ack.dueOn)}-მდე`}.
                    {ack.note && <span className="block">{ack.note}</span>}
                    {!ack.fileReady && (
                        <span className="block">
                            ფაილი ჯერ მოწმდება — დადასტურება შემოწმების შემდეგ
                            შეგიძლიათ.
                        </span>
                    )}
                </span>
            </p>
            <Button
                disabled={!ack.fileReady}
                onClick={() =>
                    router.post(
                        `/document-acknowledgements/${ack.id}/acknowledge`,
                        {},
                        options('დადასტურებულია.'),
                    )
                }
            >
                <CheckCircle2 aria-hidden /> გავეცანი
            </Button>
        </div>
    );
}

/**
 * For whoever manages the document: ask staff in chosen roles to read the
 * current published version, follow the receipts, close the request; and
 * mark the document as a template.
 */
export function AcknowledgementManager({
    documentId,
    request,
    canRequest,
    roleOptions,
    isTemplate,
    canBeTemplate,
}: {
    documentId: number;
    request: AcknowledgementRequest;
    canRequest: boolean;
    roleOptions: Record<string, string>;
    isTemplate: boolean;
    canBeTemplate: boolean;
}) {
    const [formKey, setFormKey] = useState(0);
    const [showForm, setShowForm] = useState(false);
    const done =
        request?.recipients.filter((r) => r.acknowledgedAt).length ?? 0;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        router.post(
            `/documents/${documentId}/acknowledgement-requests`,
            {
                roles: form.getAll('roles').map(String),
                due_on: String(form.get('due_on') ?? ''),
                note: String(form.get('note') ?? ''),
            },
            options('გაცნობის მოთხოვნა გაიგზავნა.', () => {
                setFormKey((k) => k + 1);
                setShowForm(false);
            }),
        );
    };

    return (
        <section
            className="mb-8 rounded-xl border border-slate-200 bg-white p-4 sm:p-6"
            aria-labelledby="ack-title"
        >
            <h2 id="ack-title" className="mb-1 text-lg font-medium">
                გაცნობის დადასტურება
            </h2>
            <p className="mb-4 text-sm text-slate-600">
                თანამშრომელი ადასტურებს, რომ გაეცნო მოქმედ ვერსიას. ფაილის
                გახსნა ან ელფოსტის მიღება დადასტურებად არ ითვლება. ახალი ვერსიის
                გამოქვეყნებისას მოთხოვნა ავტომატურად განახლდება. ეს ქვითარია და
                არა დისციპლინური შეფასება.
            </p>

            {request && (
                <div className="mb-4">
                    <p className="mb-2 text-sm">
                        ვერსია {request.versionOrdinal} ·{' '}
                        {request.roles
                            .map((r) => roleOptions[r] ?? r)
                            .join(', ')}{' '}
                        · გაეცნო <strong>{done}</strong> /{' '}
                        {request.recipients.length}
                        {request.dueOn && ` · ვადა ${formatDay(request.dueOn)}`}
                    </p>
                    <ul className="mb-3 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg text-sm ring-1 ring-slate-200">
                        {request.recipients.map((r, i) => (
                            <li
                                key={i}
                                className="flex flex-wrap justify-between gap-2 px-3 py-2"
                            >
                                <span>{r.name}</span>
                                <span
                                    className={
                                        r.acknowledgedAt
                                            ? 'text-emerald-700'
                                            : 'text-slate-500'
                                    }
                                >
                                    {r.acknowledgedAt
                                        ? `გაეცნო ${formatDateTime(r.acknowledgedAt)}`
                                        : 'ჯერ არა'}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/document-acknowledgement-requests/${request.id}/close`,
                                {},
                                options('მოთხოვნა დაიხურა.'),
                            )
                        }
                    >
                        მოთხოვნის დახურვა
                    </Button>
                </div>
            )}

            {canRequest && !showForm && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant={request ? 'outline' : 'default'}
                        onClick={() => setShowForm(true)}
                    >
                        {request ? 'ახალი მოთხოვნა' : 'გაცნობის მოთხოვნა'}
                    </Button>
                    {request && (
                        <span className="text-xs text-slate-500">
                            მიმდინარეს ჩაანაცვლებს
                        </span>
                    )}
                </div>
            )}

            {canRequest && showForm && (
                <form key={formKey} onSubmit={submit} className="grid gap-3">
                    <fieldset>
                        <legend className="mb-2 text-sm font-medium">
                            ვის სთხოვთ გაცნობას
                        </legend>
                        <div className="flex flex-wrap gap-x-4">
                            {Object.entries(roleOptions).map(
                                ([value, label]) => (
                                    <label
                                        key={value}
                                        className="flex min-h-11 items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="roles"
                                            value={value}
                                            className="size-4"
                                        />
                                        {label}
                                    </label>
                                ),
                            )}
                        </div>
                    </fieldset>
                    <div className="flex flex-wrap gap-3">
                        <label className="grid gap-1 text-sm">
                            ვადა (არასავალდებულო)
                            <Input type="date" name="due_on" />
                        </label>
                        <label className="grid min-w-[220px] flex-1 gap-1 text-sm">
                            შენიშვნა
                            <Input name="note" maxLength={500} />
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit">გაგზავნა</Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setShowForm(false)}
                        >
                            გაუქმება
                        </Button>
                    </div>
                </form>
            )}

            {(isTemplate || canBeTemplate) && (
                <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 text-sm">
                    <span>
                        {isTemplate
                            ? 'ეს დოკუმენტი შაბლონია — თანამშრომლები მისგან ახალ დოკუმენტს ქმნიან.'
                            : 'შაბლონად მონიშვნის შემდეგ თანამშრომლები მისგან ახალ დოკუმენტს შექმნიან.'}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/documents/${documentId}/template`,
                                { is_template: !isTemplate },
                                options(
                                    isTemplate
                                        ? 'შაბლონებიდან ამოიღეს.'
                                        : 'შაბლონებს დაემატა.',
                                ),
                            )
                        }
                    >
                        {isTemplate
                            ? 'შაბლონებიდან ამოღება'
                            : 'შაბლონად მონიშვნა'}
                    </Button>
                </div>
            )}
        </section>
    );
}
