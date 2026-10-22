import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { selectClass } from '@/components/documents/labels';
import { firstError } from '@/lib/assessment';
import { successUnlessReauth } from '@/lib/reauth';

export type ReviewMode = 'single' | 'sequential' | 'all';

type Props = {
    workspaceId: number;
    reviewMode: ReviewMode;
    reviewDays: number | null;
    reviewerIds: number[];
    staff: { id: number; name: string }[];
};

const modeHelp: Record<ReviewMode, string> = {
    single: 'დოკუმენტს ამტკიცებს დირექცია ან სივრცის მმართველი — ერთი გადაწყვეტილებით.',
    sequential:
        'რეცენზენტები რიგრიგობით განიხილავენ; შემდეგს ჯერი წინას დამტკიცების შემდეგ უდგება.',
    all: 'ყველა რეცენზენტმა უნდა დაამტკიცოს, ნებისმიერი რიგით.',
};

/**
 * A workspace's review rule: one decision, or a chain of named reviewers,
 * plus an optional review deadline. Applies to versions submitted later.
 */
export default function ReviewChainForm({
    workspaceId,
    reviewMode,
    reviewDays,
    reviewerIds,
    staff,
}: Props) {
    const [mode, setMode] = useState<ReviewMode>(reviewMode);
    const [days, setDays] = useState(reviewDays?.toString() ?? '');
    const [reviewers, setReviewers] = useState<number[]>(reviewerIds);
    const [adding, setAdding] = useState('');
    const [saving, setSaving] = useState(false);

    const nameOf = (id: number) =>
        staff.find((person) => person.id === id)?.name ?? `#${id}`;

    const move = (index: number, delta: number) => {
        const next = [...reviewers];
        const [item] = next.splice(index, 1);
        next.splice(index + delta, 0, item);
        setReviewers(next);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSaving(true);
        router.put(
            `/documents/workspaces/${workspaceId}/review-chain`,
            {
                review_mode: mode,
                review_days: days === '' ? null : Number(days),
                reviewer_ids: mode === 'single' ? [] : reviewers,
            },
            {
                preserveScroll: true,
                onSuccess: successUnlessReauth('განხილვის წესი შენახულია.'),
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ შეინახა.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const available = staff.filter((person) => !reviewers.includes(person.id));

    return (
        <form
            onSubmit={submit}
            className="mb-4 space-y-3 rounded-lg border border-slate-200 p-3 sm:p-4"
        >
            <h3 className="text-base font-medium">განხილვის წესი</h3>
            <div className="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                <div>
                    <label
                        htmlFor={`review-mode-${workspaceId}`}
                        className="mb-1 block text-sm"
                    >
                        ვინ ამტკიცებს
                    </label>
                    <select
                        id={`review-mode-${workspaceId}`}
                        value={mode}
                        onChange={(e) => setMode(e.target.value as ReviewMode)}
                        className={selectClass}
                    >
                        <option value="single">ერთი გადაწყვეტილება</option>
                        <option value="sequential">
                            რეცენზენტები რიგრიგობით
                        </option>
                        <option value="all">ყველა რეცენზენტი</option>
                    </select>
                </div>
                <div>
                    <label
                        htmlFor={`review-days-${workspaceId}`}
                        className="mb-1 block text-sm"
                    >
                        განხილვის ვადა (დღე, არასავალდებულო)
                    </label>
                    <Input
                        id={`review-days-${workspaceId}`}
                        type="number"
                        min={1}
                        max={60}
                        value={days}
                        onChange={(e) => setDays(e.target.value)}
                        className="h-11"
                    />
                </div>
            </div>
            <p className="text-sm text-slate-500">{modeHelp[mode]}</p>

            {mode !== 'single' && (
                <div>
                    <p className="mb-2 text-sm font-medium">რეცენზენტები</p>
                    {reviewers.length === 0 ? (
                        <p className="mb-2 text-sm text-slate-500">
                            ჯერ არავინ არის დამატებული.
                        </p>
                    ) : (
                        <ol className="mb-2 space-y-1">
                            {reviewers.map((id, index) => (
                                <li
                                    key={id}
                                    className="flex items-center gap-1 text-sm"
                                >
                                    <span className="w-6 text-slate-500">
                                        {index + 1}.
                                    </span>
                                    <span className="min-w-0 flex-1 break-words">
                                        {nameOf(id)}
                                    </span>
                                    {mode === 'sequential' && (
                                        <>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-11"
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                                aria-label={`${nameOf(id)} — ზემოთ`}
                                            >
                                                <ArrowUp size={16} />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-11"
                                                disabled={
                                                    index ===
                                                    reviewers.length - 1
                                                }
                                                onClick={() => move(index, 1)}
                                                aria-label={`${nameOf(id)} — ქვემოთ`}
                                            >
                                                <ArrowDown size={16} />
                                            </Button>
                                        </>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-11"
                                        onClick={() =>
                                            setReviewers(
                                                reviewers.filter(
                                                    (r) => r !== id,
                                                ),
                                            )
                                        }
                                        aria-label={`${nameOf(id)} — ამოშლა`}
                                    >
                                        <X size={16} />
                                    </Button>
                                </li>
                            ))}
                        </ol>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <label
                            htmlFor={`add-reviewer-${workspaceId}`}
                            className="sr-only"
                        >
                            რეცენზენტის დამატება
                        </label>
                        <select
                            id={`add-reviewer-${workspaceId}`}
                            value={adding}
                            onChange={(e) => setAdding(e.target.value)}
                            className={`${selectClass} min-w-0 flex-[1_1_12rem]`}
                        >
                            <option value="">აირჩიეთ თანამშრომელი</option>
                            {available.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={adding === ''}
                            onClick={() => {
                                setReviewers([...reviewers, Number(adding)]);
                                setAdding('');
                            }}
                        >
                            დამატება
                        </Button>
                    </div>
                    <p className="mt-2 text-xs text-slate-500">
                        ავტორი საკუთარ ვერსიას ვერ დაამტკიცებს — მისი ნაბიჯი
                        გამოტოვდება.
                    </p>
                </div>
            )}

            <Button type="submit" disabled={saving} className="min-h-11">
                შენახვა
            </Button>
        </form>
    );
}
