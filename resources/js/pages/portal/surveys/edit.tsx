import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowDown, ArrowLeft, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    AUDIENCES,
    QUESTION_TYPES,
    type Survey,
    type SurveyQuestion,
} from '@/lib/surveys';

const blank = (): SurveyQuestion => ({
    type: 'single',
    prompt: '',
    options: ['', ''],
    isRequired: true,
});

/**
 * The survey builder for a draft: details, then questions in order. The
 * whole draft is saved at once; once opened, questions no longer change.
 */
export default function SurveyEdit({ survey }: { survey: Survey | null }) {
    const [title, setTitle] = useState(survey?.title ?? '');
    const [description, setDescription] = useState(survey?.description ?? '');
    const [audience, setAudience] = useState(survey?.audience ?? 'guardians');
    const [anonymous, setAnonymous] = useState(survey?.isAnonymous ?? true);
    const [closesOn, setClosesOn] = useState(survey?.closesOn ?? '');
    const [questions, setQuestions] = useState<SurveyQuestion[]>(
        survey?.questions.length ? survey.questions : [blank()],
    );
    const [saving, setSaving] = useState(false);

    const change = (index: number, patch: Partial<SurveyQuestion>) =>
        setQuestions((all) =>
            all.map((q, i) => (i === index ? { ...q, ...patch } : q)),
        );
    const move = (index: number, by: number) =>
        setQuestions((all) => {
            const next = [...all];
            [next[index], next[index + by]] = [next[index + by], next[index]];

            return next;
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        const data = {
            title,
            description,
            audience,
            is_anonymous: anonymous,
            closes_on: closesOn || null,
            questions: questions.map((q) => ({
                type: q.type,
                prompt: q.prompt,
                options: q.options,
                is_required: q.isRequired,
            })),
        };
        const options = {
            onError: (errors: Record<string, string>) =>
                toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმა.'),
            onFinish: () => setSaving(false),
        };
        survey
            ? router.put(`/portal/surveys/${survey.id}`, data, options)
            : router.post('/portal/surveys', data, options);
    };

    const field =
        'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

    return (
        <PortalLayout>
            <Head
                title={survey ? 'გამოკითხვის რედაქტირება' : 'ახალი გამოკითხვა'}
            />
            <Link
                href={
                    survey ? `/portal/surveys/${survey.id}` : '/portal/surveys'
                }
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> უკან
            </Link>
            <h1 className="mb-6 text-2xl">
                {survey ? 'გამოკითხვის რედაქტირება' : 'ახალი გამოკითხვა'}
            </h1>

            <form onSubmit={submit} className="space-y-6">
                <section className="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="title"
                            className="mb-1 block text-sm font-medium"
                        >
                            სათაური
                        </label>
                        <Input
                            id="title"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            required
                            maxLength={160}
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="description"
                            className="mb-1 block text-sm font-medium"
                        >
                            შესავალი ტექსტი
                        </label>
                        <textarea
                            id="description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            rows={3}
                            maxLength={3000}
                            className="w-full rounded-md border border-slate-300 p-3 text-sm"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="audience"
                            className="mb-1 block text-sm font-medium"
                        >
                            ვისთვის
                        </label>
                        <select
                            id="audience"
                            value={audience}
                            onChange={(e) => setAudience(e.target.value)}
                            className={field}
                        >
                            {Object.entries(AUDIENCES).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label
                            htmlFor="closes_on"
                            className="mb-1 block text-sm font-medium"
                        >
                            ბოლო დღე (არასავალდებულო)
                        </label>
                        <Input
                            id="closes_on"
                            type="date"
                            value={closesOn}
                            onChange={(e) => setClosesOn(e.target.value)}
                        />
                    </div>
                    <label className="flex min-h-11 items-center gap-2 text-sm sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={anonymous}
                            onChange={(e) => setAnonymous(e.target.checked)}
                        />
                        ანონიმური: პასუხებს სახელი არ მიეწერება
                    </label>
                </section>

                <ol className="space-y-4">
                    {questions.map((question, index) => (
                        <li
                            key={index}
                            className="rounded-xl border border-slate-200 bg-white p-4"
                        >
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-sm font-medium">
                                    კითხვა {index + 1}
                                </h2>
                                <div className="flex gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="ზემოთ"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        <ArrowUp className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="ქვემოთ"
                                        disabled={
                                            index === questions.length - 1
                                        }
                                        onClick={() => move(index, 1)}
                                    >
                                        <ArrowDown className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="კითხვის წაშლა"
                                        disabled={questions.length === 1}
                                        onClick={() =>
                                            setQuestions((all) =>
                                                all.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-[1fr_200px]">
                                <div>
                                    <label
                                        htmlFor={`prompt-${index}`}
                                        className="mb-1 block text-xs font-medium"
                                    >
                                        კითხვა
                                    </label>
                                    <Input
                                        id={`prompt-${index}`}
                                        value={question.prompt}
                                        required
                                        maxLength={500}
                                        onChange={(e) =>
                                            change(index, {
                                                prompt: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <label
                                        htmlFor={`type-${index}`}
                                        className="mb-1 block text-xs font-medium"
                                    >
                                        სახე
                                    </label>
                                    <select
                                        id={`type-${index}`}
                                        value={question.type}
                                        className={field}
                                        onChange={(e) =>
                                            change(index, {
                                                type: e.target
                                                    .value as SurveyQuestion['type'],
                                                options: question.options.length
                                                    ? question.options
                                                    : ['', ''],
                                            })
                                        }
                                    >
                                        {Object.entries(QUESTION_TYPES).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                            </div>
                            {(question.type === 'single' ||
                                question.type === 'multiple') && (
                                <fieldset className="mt-3 space-y-2">
                                    <legend className="mb-1 text-xs font-medium">
                                        პასუხის ვარიანტები
                                    </legend>
                                    {question.options.map(
                                        (option, optionIndex) => (
                                            <div
                                                key={optionIndex}
                                                className="flex gap-2"
                                            >
                                                <Input
                                                    aria-label={`ვარიანტი ${optionIndex + 1}`}
                                                    value={option}
                                                    maxLength={200}
                                                    onChange={(e) =>
                                                        change(index, {
                                                            options:
                                                                question.options.map(
                                                                    (o, i) =>
                                                                        i ===
                                                                        optionIndex
                                                                            ? e
                                                                                  .target
                                                                                  .value
                                                                            : o,
                                                                ),
                                                        })
                                                    }
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="ვარიანტის წაშლა"
                                                    disabled={
                                                        question.options
                                                            .length <= 2
                                                    }
                                                    onClick={() =>
                                                        change(index, {
                                                            options:
                                                                question.options.filter(
                                                                    (_, i) =>
                                                                        i !==
                                                                        optionIndex,
                                                                ),
                                                        })
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        ),
                                    )}
                                    {question.options.length < 20 && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                change(index, {
                                                    options: [
                                                        ...question.options,
                                                        '',
                                                    ],
                                                })
                                            }
                                        >
                                            ვარიანტის დამატება
                                        </Button>
                                    )}
                                </fieldset>
                            )}
                            <label className="mt-3 flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={question.isRequired}
                                    onChange={(e) =>
                                        change(index, {
                                            isRequired: e.target.checked,
                                        })
                                    }
                                />
                                სავალდებულო
                            </label>
                        </li>
                    ))}
                </ol>

                <div className="flex flex-wrap gap-3">
                    {questions.length < 50 && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                setQuestions((all) => [...all, blank()])
                            }
                        >
                            <Plus className="mr-2 h-4 w-4" /> კითხვის დამატება
                        </Button>
                    )}
                    <Button type="submit" disabled={saving}>
                        მონახაზის შენახვა
                    </Button>
                </div>
            </form>
        </PortalLayout>
    );
}
