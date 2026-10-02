import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, ChevronLeft, ChevronRight } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';
import { postWithToast } from '@/lib/operations';

type Menu = {
    id: number;
    version: number;
    status: string;
    items: { course: string; name: string; allergens: string[] }[];
};

type Props = {
    isManagement: boolean;
    weekStart: string;
    allergenCodes: Record<string, string>;
    cutoffHour: number;
    days: { date: string; published: Menu | null; draft: Menu | null }[];
    children: {
        id: number;
        name: string;
        allergens: string[];
        optedOutDays: string[];
    }[];
    kitchen: {
        days: { date: string; expected: number }[];
        allergenList: {
            studentName: string;
            className: string | null;
            allergens: string[];
        }[];
    } | null;
};

type ItemDraft = { course: string; name: string; allergens: string[] };

function shiftWeek(weekStart: string, days: number): string {
    const date = new Date(`${weekStart}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

function DraftEditor({
    date,
    draft,
    codes,
}: {
    date: string;
    draft: Menu | null;
    codes: Record<string, string>;
}) {
    const [items, setItems] = useState<ItemDraft[]>(
        draft?.items ?? [{ course: 'მთავარი', name: '', allergens: [] }],
    );
    const update = (i: number, patch: Partial<ItemDraft>) =>
        setItems((all) =>
            all.map((x, j) => (j === i ? { ...x, ...patch } : x)),
        );

    return (
        <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
            {items.map((item, i) => (
                <div key={i} className="space-y-1">
                    <div className="flex gap-2">
                        <Input
                            aria-label="კერძის ტიპი"
                            value={item.course}
                            onChange={(e) =>
                                update(i, { course: e.target.value })
                            }
                            className="w-28"
                        />
                        <Input
                            aria-label="კერძი"
                            value={item.name}
                            onChange={(e) =>
                                update(i, { name: e.target.value })
                            }
                            placeholder="კერძი"
                        />
                    </div>
                    <details className="text-xs">
                        <summary className="min-h-11 cursor-pointer leading-[44px]">
                            ალერგენები ({item.allergens.length})
                        </summary>
                        <div className="grid grid-cols-2 gap-1">
                            {Object.entries(codes).map(([code, label]) => (
                                <label
                                    key={code}
                                    className="flex min-h-9 items-center gap-1"
                                >
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4"
                                        checked={item.allergens.includes(code)}
                                        onChange={(e) =>
                                            update(i, {
                                                allergens: e.target.checked
                                                    ? [...item.allergens, code]
                                                    : item.allergens.filter(
                                                          (c) => c !== code,
                                                      ),
                                            })
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                    </details>
                </div>
            ))}
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        setItems((all) => [
                            ...all,
                            { course: 'მთავარი', name: '', allergens: [] },
                        ])
                    }
                >
                    + კერძი
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        postWithToast(
                            '/portal/meals/menus',
                            {
                                served_on: date,
                                items: items.filter(
                                    (x) => x.name.trim() !== '',
                                ),
                            },
                            'დრაფტი შენახულია.',
                        )
                    }
                >
                    დრაფტის შენახვა
                </Button>
                {draft && (
                    <Button
                        size="sm"
                        onClick={() =>
                            postWithToast(
                                `/portal/meals/menus/${draft.id}/publish`,
                                {},
                                `ვერსია ${draft.version} გამოქვეყნდა.`,
                            )
                        }
                    >
                        გამოქვეყნება
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * Weekly menu. Families see only published versions and a warning when a
 * dish carries one of their child's allergens; the kitchen sees allergen
 * codes per student and expected counts — never health details.
 */
export default function MealsIndex({
    isManagement,
    weekStart,
    allergenCodes,
    cutoffHour,
    days,
    children,
    kitchen,
}: Props) {
    const goWeek = (offset: number) =>
        router.get(
            '/portal/meals',
            { week: shiftWeek(weekStart, offset) },
            { preserveState: false },
        );

    return (
        <PortalLayout>
            <Head title="კვება" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">კვება</h1>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        className="h-11 w-11"
                        aria-label="წინა კვირა"
                        onClick={() => goWeek(-7)}
                    >
                        <ChevronLeft size={18} />
                    </Button>
                    <span className="text-sm">{formatDay(weekStart)}-დან</span>
                    <Button
                        variant="outline"
                        size="icon"
                        className="h-11 w-11"
                        aria-label="შემდეგი კვირა"
                        onClick={() => goWeek(7)}
                    >
                        <ChevronRight size={18} />
                    </Button>
                </div>
            </div>

            {children.map((child) => (
                <section
                    key={child.id}
                    className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                >
                    <h2 className="mb-2 text-lg">{child.name}</h2>
                    <fieldset>
                        <legend className="mb-1 text-sm font-medium">
                            ალერგენები სამზარეულოსთვის
                        </legend>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                const selected = new FormData(
                                    e.currentTarget,
                                ).getAll('allergens');
                                postWithToast(
                                    `/portal/meals/students/${child.id}/dietary`,
                                    { allergens: selected },
                                    'შენახულია.',
                                );
                            }}
                        >
                            <div className="grid grid-cols-2 gap-1 text-sm sm:grid-cols-4">
                                {Object.entries(allergenCodes).map(
                                    ([code, label]) => (
                                        <label
                                            key={code}
                                            className="flex min-h-11 items-center gap-2"
                                        >
                                            <input
                                                type="checkbox"
                                                name="allergens"
                                                value={code}
                                                defaultChecked={child.allergens.includes(
                                                    code,
                                                )}
                                                className="h-4 w-4"
                                            />
                                            {label}
                                        </label>
                                    ),
                                )}
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                type="submit"
                                className="mt-2"
                            >
                                შენახვა
                            </Button>
                        </form>
                    </fieldset>
                </section>
            ))}

            <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {days.map((day) => (
                    <li
                        key={day.date}
                        className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                    >
                        <h2 className="mb-2 font-semibold">
                            {formatDay(day.date)}
                            {kitchen && (
                                <span className="ml-2 text-sm font-normal text-slate-500">
                                    · მოსალოდნელი{' '}
                                    {kitchen.days.find(
                                        (k) => k.date === day.date,
                                    )?.expected ?? 0}
                                </span>
                            )}
                        </h2>
                        {day.published === null ? (
                            <p className="text-sm text-slate-500">
                                მენიუ ჯერ არ გამოქვეყნებულა.
                            </p>
                        ) : (
                            <ul className="space-y-1 text-sm">
                                {day.published.items.map((item, i) => {
                                    const warnFor = children.filter((c) =>
                                        item.allergens.some((a) =>
                                            c.allergens.includes(a),
                                        ),
                                    );

                                    return (
                                        <li key={i}>
                                            <span className="text-slate-500">
                                                {item.course}:
                                            </span>{' '}
                                            {item.name}
                                            {item.allergens.length > 0 && (
                                                <span className="block text-xs text-slate-500">
                                                    {item.allergens
                                                        .map(
                                                            (a) =>
                                                                allergenCodes[
                                                                    a
                                                                ] ?? a,
                                                        )
                                                        .join(', ')}
                                                </span>
                                            )}
                                            {warnFor.map((c) => (
                                                <span
                                                    key={c.id}
                                                    className="mt-0.5 flex items-center gap-1 text-xs font-medium text-red-700"
                                                >
                                                    <AlertTriangle
                                                        size={12}
                                                        aria-hidden
                                                    />{' '}
                                                    {c.name}-ისთვის ალერგენი
                                                </span>
                                            ))}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {children.map((child) => {
                            const out = child.optedOutDays.includes(day.date);

                            return (
                                <label
                                    key={child.id}
                                    className="mt-3 flex min-h-11 items-center gap-2 border-t border-slate-100 pt-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4"
                                        checked={!out}
                                        onChange={() =>
                                            postWithToast(
                                                `/portal/meals/students/${child.id}/opt-out`,
                                                {
                                                    served_on: day.date,
                                                    opt_out: !out,
                                                },
                                                'შენახულია.',
                                            )
                                        }
                                    />
                                    {child.name} ისადილებს
                                </label>
                            );
                        })}
                        {isManagement && (
                            <DraftEditor
                                key={`${day.date}-${day.draft?.id ?? 'new'}`}
                                date={day.date}
                                draft={day.draft}
                                codes={allergenCodes}
                            />
                        )}
                    </li>
                ))}
            </ul>
            {children.length > 0 && (
                <p className="mt-4 text-xs text-slate-500">
                    დღის კვებაზე უარის თქმა შესაძლებელია იმავე დღის {cutoffHour}
                    :00-მდე.
                </p>
            )}

            {kitchen && kitchen.allergenList.length > 0 && (
                <section className="mt-8" aria-labelledby="kitchen-allergens">
                    <h2 id="kitchen-allergens" className="mb-3 text-lg">
                        ალერგენები მოსწავლეების მიხედვით
                    </h2>
                    <ul className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                        {kitchen.allergenList.map((row, i) => (
                            <li
                                key={i}
                                className="flex flex-wrap justify-between gap-2 p-3"
                            >
                                <span>
                                    {row.studentName}{' '}
                                    <span className="text-slate-500">
                                        {row.className}
                                    </span>
                                </span>
                                <span>
                                    {row.allergens
                                        .map((a) => allergenCodes[a] ?? a)
                                        .join(', ')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PortalLayout>
    );
}
