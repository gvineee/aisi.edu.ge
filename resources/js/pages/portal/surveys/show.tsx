import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft, CircleCheck, Download } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { formatDay } from '@/lib/assessment';
import { AUDIENCES, SURVEY_STATUS, type Survey } from '@/lib/surveys';

type Results = {
    responses: number;
    byRole: Record<string, number>;
    questions: {
        id: number;
        type: string;
        prompt: string;
        answered: number;
        options: { label: string; count: number }[];
        mean: number | null;
        texts: { text: string; name: string | null }[];
    }[];
};

type Props = {
    survey: Survey;
    isManager: boolean;
    canAnswer: boolean;
    answered: boolean;
    results: Results | null;
    audienceSize: number | null;
};

const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'შემოწმეთ პასუხები.');

/**
 * Management sees the survey's state and results; a respondent answers it
 * once (or sees that they already have).
 */
export default function SurveyShow({
    survey,
    isManager,
    canAnswer,
    answered,
    results,
    audienceSize,
}: Props) {
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const base = `/portal/surveys/${survey.id}`;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const answers: Record<number, string | string[]> = {};
        survey.questions.forEach((question) => {
            if (question.id === undefined) return;
            if (question.type === 'multiple')
                answers[question.id] = form
                    .getAll(`q-${question.id}`)
                    .map(String);
            else
                answers[question.id] = String(
                    form.get(`q-${question.id}`) ?? '',
                );
        });
        setSaving(true);
        router.post(
            `${base}/responses`,
            { answers },
            { preserveScroll: true, onError, onFinish: () => setSaving(false) },
        );
    };

    return (
        <PortalLayout>
            <Head title={survey.title} />
            <Link
                href="/portal/surveys"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> გამოკითხვები
            </Link>
            <div className="mb-2 flex flex-wrap items-start justify-between gap-4">
                <h1 className="text-2xl">{survey.title}</h1>
                {isManager && (
                    <div className="flex flex-wrap gap-2">
                        {survey.status === 'draft' && (
                            <>
                                <Button asChild variant="outline">
                                    <Link href={`${base}/edit`}>
                                        რედაქტირება
                                    </Link>
                                </Button>
                                <Button
                                    onClick={() =>
                                        router.post(
                                            `${base}/status`,
                                            { status: 'open' },
                                            { onError },
                                        )
                                    }
                                >
                                    გახსნა
                                </Button>
                                {confirmDelete ? (
                                    <Button
                                        variant="destructive"
                                        onClick={() =>
                                            router.delete(base, { onError })
                                        }
                                    >
                                        დიახ, წაშალე
                                    </Button>
                                ) : (
                                    <Button
                                        variant="ghost"
                                        onClick={() => setConfirmDelete(true)}
                                    >
                                        წაშლა
                                    </Button>
                                )}
                            </>
                        )}
                        {survey.status === 'open' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `${base}/status`,
                                        { status: 'closed' },
                                        { onError },
                                    )
                                }
                            >
                                დახურვა
                            </Button>
                        )}
                        {survey.status !== 'draft' && (
                            <Button asChild variant="outline">
                                <a href={`${base}/export`}>
                                    <Download className="mr-2 h-4 w-4" /> CSV
                                </a>
                            </Button>
                        )}
                    </div>
                )}
            </div>
            <p className="mb-6 text-sm text-slate-500">
                {isManager && (
                    <span
                        className={`mr-2 rounded-full px-2 py-0.5 text-xs ${SURVEY_STATUS[survey.status].chip}`}
                    >
                        {SURVEY_STATUS[survey.status].label}
                    </span>
                )}
                {AUDIENCES[survey.audience] ?? survey.audience}
                {survey.isAnonymous ? ' · ანონიმური' : ' · პასუხი სახელით'}
                {survey.closesOn && ` · ${formatDay(survey.closesOn)}-მდე`}
            </p>
            {survey.description && (
                <p className="mb-6 max-w-2xl whitespace-pre-line text-slate-700">
                    {survey.description}
                </p>
            )}

            {isManager && results ? (
                <section aria-labelledby="results">
                    <h2 id="results" className="mb-1 text-lg">
                        შედეგები
                    </h2>
                    <p className="mb-4 text-sm text-slate-500">
                        {results.responses} პასუხი
                        {audienceSize !== null &&
                            ` ${audienceSize} ადრესატიდან`}
                    </p>
                    <ol className="space-y-4">
                        {results.questions.map((question, index) => {
                            const max = Math.max(
                                1,
                                ...question.options.map((o) => o.count),
                            );

                            return (
                                <li
                                    key={question.id}
                                    className="rounded-xl border border-slate-200 bg-white p-4"
                                >
                                    <h3 className="mb-1 font-medium">
                                        {index + 1}. {question.prompt}
                                    </h3>
                                    <p className="mb-3 text-xs text-slate-500">
                                        უპასუხა {question.answered}
                                        {question.mean !== null &&
                                            ` · საშუალო ${question.mean.toLocaleString('ka-GE')}`}
                                    </p>
                                    {question.options.length > 0 && (
                                        <ul className="space-y-2 text-sm">
                                            {question.options.map((option) => (
                                                <li key={option.label}>
                                                    <div className="mb-0.5 flex justify-between gap-2">
                                                        <span>
                                                            {option.label}
                                                        </span>
                                                        <span className="text-slate-500">
                                                            {option.count}
                                                        </span>
                                                    </div>
                                                    <div
                                                        className="h-2 rounded-full bg-slate-100"
                                                        aria-hidden
                                                    >
                                                        <div
                                                            className="h-2 rounded-full bg-sky-500"
                                                            style={{
                                                                width: `${(option.count / max) * 100}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {question.type === 'text' &&
                                        (question.texts.length === 0 ? (
                                            <p className="text-sm text-slate-500">
                                                პასუხი ჯერ არ არის.
                                            </p>
                                        ) : (
                                            <ul className="max-h-80 space-y-2 overflow-y-auto text-sm">
                                                {question.texts.map(
                                                    (text, i) => (
                                                        <li
                                                            key={i}
                                                            className="rounded-lg bg-slate-50 p-2 whitespace-pre-line"
                                                        >
                                                            {text.text}
                                                            {text.name && (
                                                                <span className="mt-1 block text-xs text-slate-500">
                                                                    —{' '}
                                                                    {text.name}
                                                                </span>
                                                            )}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ))}
                                </li>
                            );
                        })}
                    </ol>
                </section>
            ) : answered ? (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
                    <CircleCheck className="mx-auto mb-2 h-8 w-8 text-emerald-600" />
                    <p className="font-medium">
                        გმადლობთ, თქვენი პასუხი მიღებულია.
                    </p>
                </div>
            ) : !canAnswer ? (
                <p className="text-slate-500">გამოკითხვა დასრულებულია.</p>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    {survey.questions.map((question, index) => (
                        <fieldset
                            key={question.id}
                            className="rounded-xl border border-slate-200 bg-white p-4"
                        >
                            <legend className="px-1 font-medium">
                                {index + 1}. {question.prompt}
                                {question.isRequired && (
                                    <span
                                        className="text-rose-600"
                                        aria-label="სავალდებულო"
                                    >
                                        {' '}
                                        *
                                    </span>
                                )}
                            </legend>
                            {question.type === 'text' ? (
                                <textarea
                                    name={`q-${question.id}`}
                                    rows={3}
                                    maxLength={2000}
                                    aria-label={question.prompt}
                                    className="mt-2 w-full rounded-md border border-slate-300 p-3 text-sm"
                                />
                            ) : question.type === 'scale' ? (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {[1, 2, 3, 4, 5].map((value) => (
                                        <label
                                            key={value}
                                            className="flex min-h-11 min-w-11 items-center justify-center gap-1 rounded-lg border border-slate-200 px-3 text-sm"
                                        >
                                            <input
                                                type="radio"
                                                name={`q-${question.id}`}
                                                value={value}
                                            />{' '}
                                            {value}
                                        </label>
                                    ))}
                                    <span className="w-full text-xs text-slate-500">
                                        1 — სულ არა, 5 — ძალიან
                                    </span>
                                </div>
                            ) : (
                                <div className="mt-2 space-y-1">
                                    {question.options.map(
                                        (option, optionIndex) => (
                                            <label
                                                key={optionIndex}
                                                className="flex min-h-11 items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type={
                                                        question.type ===
                                                        'multiple'
                                                            ? 'checkbox'
                                                            : 'radio'
                                                    }
                                                    name={`q-${question.id}`}
                                                    value={optionIndex}
                                                />
                                                {option}
                                            </label>
                                        ),
                                    )}
                                </div>
                            )}
                        </fieldset>
                    ))}
                    <p className="text-xs text-slate-500">
                        {survey.isAnonymous
                            ? 'გამოკითხვა ანონიმურია: პასუხს თქვენი სახელი არ მიეწერება.'
                            : 'პასუხი თქვენი სახელით შეინახება.'}{' '}
                        უპასუხება შეიძლება ერთხელ.
                    </p>
                    <Button type="submit" disabled={saving}>
                        პასუხის გაგზავნა
                    </Button>
                </form>
            )}
        </PortalLayout>
    );
}
