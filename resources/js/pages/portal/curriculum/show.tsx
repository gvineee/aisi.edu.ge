import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Check,
    Pencil,
    Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';
import { PLAN_STATUS, Progress } from '@/components/curriculum/progress';

type Topic = {
    id: number;
    title: string;
    plannedHours: number;
    plannedFrom: string | null;
    plannedTo: string | null;
    lessons: number;
    firstOn: string | null;
    lastOn: string | null;
};

type Props = {
    plan: {
        id: number;
        status: 'draft' | 'approved';
        notes: string | null;
        className: string;
        subjectName: string;
        approvedAt: string | null;
    };
    topics: Topic[];
    stats: {
        topics: number;
        covered: number;
        overdue: number;
        plannedHours: number;
        journalLessons: number;
        linkedLessons: number;
    };
    today: string;
    canEdit: boolean;
    isManager: boolean;
    sources: { id: number; label: string }[];
};

const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

/**
 * One class's plan in one subject: its topics in order, which of them the
 * class register shows as covered, and editing for whoever may.
 */
export default function CurriculumShow({
    plan,
    topics,
    stats,
    today,
    canEdit,
    isManager,
    sources,
}: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const base = `/portal/curriculum/${plan.id}`;

    const addTopics = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        setSaving(true);
        router.post(`${base}/topics`, new FormData(target), {
            preserveScroll: true,
            onSuccess: () => target.reset(),
            onError,
            onFinish: () => setSaving(false),
        });
    };

    const saveTopic = (event: FormEvent<HTMLFormElement>, topic: Topic) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        data.append('_method', 'put');
        router.post(`${base}/topics/${topic.id}`, data, {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
            onError,
        });
    };

    const lessonsLeft = Math.max(0, stats.plannedHours - stats.linkedLessons);

    return (
        <PortalLayout>
            <Head title={`${plan.subjectName} · ${plan.className}`} />
            <Link
                href="/portal/curriculum"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> სასწავლო გეგმები
            </Link>
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="mb-1 text-2xl">
                        {plan.subjectName} · {plan.className}
                    </h1>
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs ${PLAN_STATUS[plan.status].chip}`}
                    >
                        {PLAN_STATUS[plan.status].label}
                    </span>
                </div>
                {isManager && (
                    <Button
                        variant={
                            plan.status === 'approved' ? 'outline' : 'default'
                        }
                        onClick={() =>
                            router.post(
                                `${base}/status`,
                                {
                                    status:
                                        plan.status === 'approved'
                                            ? 'draft'
                                            : 'approved',
                                },
                                { preserveScroll: true, onError },
                            )
                        }
                    >
                        {plan.status === 'approved'
                            ? 'მონახაზად დაბრუნება'
                            : 'დამტკიცება'}
                    </Button>
                )}
            </div>

            <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Stat
                    label="გავლილი თემა"
                    value={`${stats.covered} / ${stats.topics}`}
                />
                <Stat
                    label="ჩამორჩენილი თემა"
                    value={String(stats.overdue)}
                    warn={stats.overdue > 0}
                />
                <Stat
                    label="დაგეგმილი გაკვეთილი"
                    value={String(stats.plannedHours)}
                />
                <Stat
                    label="ჟურნალში ჩაწერილი"
                    value={`${stats.journalLessons} (გეგმით ${stats.linkedLessons})`}
                />
            </dl>
            {stats.topics > 0 && (
                <div className="mb-6 max-w-md">
                    <Progress
                        covered={stats.covered}
                        total={stats.topics}
                        overdue={stats.overdue}
                    />
                    <p className="mt-1 text-xs text-slate-500">
                        გეგმით დარჩა დაახლოებით {lessonsLeft} გაკვეთილი.
                    </p>
                </div>
            )}

            {topics.length === 0 ? (
                <p className="mb-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    გეგმაში თემები ჯერ არ არის.
                </p>
            ) : (
                <ol className="mb-8 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {topics.map((topic, index) => {
                        const covered = topic.lessons > 0;
                        const overdue =
                            !covered &&
                            topic.plannedTo !== null &&
                            topic.plannedTo < today;

                        return (
                            <li key={topic.id} className="p-3 text-sm sm:px-4">
                                {editing === topic.id ? (
                                    <form
                                        onSubmit={(event) =>
                                            saveTopic(event, topic)
                                        }
                                        className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_100px_150px_150px_auto] sm:items-end"
                                    >
                                        <div>
                                            <label
                                                htmlFor={`title-${topic.id}`}
                                                className="mb-1 block text-xs font-medium"
                                            >
                                                თემა
                                            </label>
                                            <Input
                                                id={`title-${topic.id}`}
                                                name="title"
                                                required
                                                maxLength={200}
                                                defaultValue={topic.title}
                                            />
                                        </div>
                                        <div>
                                            <label
                                                htmlFor={`hours-${topic.id}`}
                                                className="mb-1 block text-xs font-medium"
                                            >
                                                გაკვეთილი
                                            </label>
                                            <Input
                                                id={`hours-${topic.id}`}
                                                name="planned_hours"
                                                type="number"
                                                min={1}
                                                max={200}
                                                required
                                                defaultValue={
                                                    topic.plannedHours
                                                }
                                            />
                                        </div>
                                        <div>
                                            <label
                                                htmlFor={`from-${topic.id}`}
                                                className="mb-1 block text-xs font-medium"
                                            >
                                                დან
                                            </label>
                                            <Input
                                                id={`from-${topic.id}`}
                                                name="planned_from"
                                                type="date"
                                                defaultValue={
                                                    topic.plannedFrom ?? ''
                                                }
                                            />
                                        </div>
                                        <div>
                                            <label
                                                htmlFor={`to-${topic.id}`}
                                                className="mb-1 block text-xs font-medium"
                                            >
                                                მდე
                                            </label>
                                            <Input
                                                id={`to-${topic.id}`}
                                                name="planned_to"
                                                type="date"
                                                defaultValue={
                                                    topic.plannedTo ?? ''
                                                }
                                            />
                                        </div>
                                        <div className="flex gap-2">
                                            <Button type="submit">
                                                შენახვა
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => setEditing(null)}
                                            >
                                                გაუქმება
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <span
                                            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs ${covered ? 'bg-emerald-100 text-emerald-900' : overdue ? 'bg-rose-100 text-rose-900' : 'bg-slate-100 text-slate-600'}`}
                                            aria-label={
                                                covered
                                                    ? 'გავლილია'
                                                    : overdue
                                                      ? 'ჩამორჩება'
                                                      : 'ჯერ არ გავლილა'
                                            }
                                        >
                                            {covered ? (
                                                <Check size={14} />
                                            ) : (
                                                index + 1
                                            )}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {topic.title}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {topic.plannedHours} გაკვ.
                                                {topic.plannedFrom &&
                                                    ` · ${formatDay(topic.plannedFrom)}`}
                                                {topic.plannedTo &&
                                                    ` – ${formatDay(topic.plannedTo)}`}
                                                {covered &&
                                                    topic.firstOn &&
                                                    ` · გავლილი: ${topic.lessons} გაკვ., ${formatDay(topic.firstOn)}${topic.lastOn !== topic.firstOn && topic.lastOn ? ` – ${formatDay(topic.lastOn)}` : ''}`}
                                                {overdue && ' · ვადა გავიდა'}
                                            </p>
                                        </div>
                                        {canEdit && (
                                            <div className="flex gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="ზემოთ"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/topics/${topic.id}/move`,
                                                            { direction: 'up' },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <ArrowUp className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="ქვემოთ"
                                                    disabled={
                                                        index ===
                                                        topics.length - 1
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/topics/${topic.id}/move`,
                                                            {
                                                                direction:
                                                                    'down',
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <ArrowDown className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="რედაქტირება"
                                                    onClick={() =>
                                                        setEditing(topic.id)
                                                    }
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="წაშლა"
                                                    onClick={() =>
                                                        router.delete(
                                                            `${base}/topics/${topic.id}`,
                                                            {
                                                                preserveScroll: true,
                                                                onError,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}

            {canEdit ? (
                <section
                    aria-labelledby="add-topics"
                    className="grid gap-6 lg:grid-cols-2"
                >
                    <form
                        onSubmit={addTopics}
                        className="rounded-xl border border-slate-200 bg-white p-4"
                    >
                        <h2 id="add-topics" className="mb-2 text-lg">
                            თემების დამატება
                        </h2>
                        <label
                            htmlFor="lines"
                            className="mb-1 block text-sm text-slate-600"
                        >
                            თითო ხაზზე ერთი თემა. გაკვეთილების რაოდენობა „|“-ის
                            შემდეგ, მაგ.: „წილადები | 6“
                        </label>
                        <textarea
                            id="lines"
                            name="lines"
                            rows={6}
                            required
                            className="mb-3 w-full rounded-md border border-slate-300 p-3 text-sm"
                        />
                        <Button type="submit" disabled={saving}>
                            დამატება
                        </Button>
                    </form>
                    {sources.length > 0 && (
                        <form
                            onSubmit={addTopics}
                            className="rounded-xl border border-slate-200 bg-white p-4"
                        >
                            <h2 className="mb-2 text-lg">
                                სხვა კლასის გეგმიდან
                            </h2>
                            <label
                                htmlFor="copy_from"
                                className="mb-1 block text-sm text-slate-600"
                            >
                                თემები დაემატება ამ გეგმის ბოლოს
                            </label>
                            <select
                                id="copy_from"
                                name="copy_from"
                                required
                                className="mb-3 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {sources.map((source) => (
                                    <option key={source.id} value={source.id}>
                                        {source.label}
                                    </option>
                                ))}
                            </select>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={saving}
                            >
                                კოპირება
                            </Button>
                        </form>
                    )}
                </section>
            ) : (
                plan.status === 'approved' && (
                    <p className="text-sm text-slate-500">
                        დამტკიცებულ გეგმას ადმინისტრაცია ცვლის.
                    </p>
                )
            )}
        </PortalLayout>
    );
}

function Stat({
    label,
    value,
    warn = false,
}: {
    label: string;
    value: string;
    warn?: boolean;
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-3">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd
                className={`text-lg font-semibold ${warn ? 'text-rose-700' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}
