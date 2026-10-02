import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDay, formatScore } from '@/lib/assessment';

type Props = {
    academicYears: {
        id: number;
        name: string;
        startsOn: string;
        endsOn: string;
    }[];
    periods: {
        id: number;
        name: string;
        academicYearName: string | null;
        startsOn: string;
        endsOn: string;
        status: string;
        closedAt: string | null;
    }[];
    scales: {
        id: number;
        name: string;
        version: number;
        isActive: boolean;
        bands: { minPercent: string; label: string }[];
    }[];
    categories: { id: number; name: string }[];
};

type BandDraft = { min_percent: string; label: string };

const sectionCard = 'rounded-xl border border-slate-200 bg-white';
const fieldLabel = 'mb-1 block text-sm font-medium';

/**
 * School grading setup for academic manager / director / admin. Nothing
 * here is pre-filled: periods, the grade scale and categories are the
 * school's own approved policy.
 */
export default function AssessmentSettingsIndex({
    academicYears,
    periods,
    scales,
    categories,
}: Props) {
    const [sending, setSending] = useState(false);
    const [reopenId, setReopenId] = useState<number | null>(null);
    const [reopenReason, setReopenReason] = useState('');
    const [showScaleForm, setShowScaleForm] = useState(false);
    const [bands, setBands] = useState<BandDraft[]>([
        { min_percent: '0', label: '' },
    ]);

    const post = (
        url: string,
        data: FormData | Record<string, unknown>,
        success: string,
        after?: () => void,
    ) => {
        setSending(true);
        router.post(url, data as Record<string, string>, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(success);
                after?.();
            },
            onError: (errors) =>
                toast.error(firstError(errors) ?? 'შეამოწმეთ ველები.'),
            onFinish: () => setSending(false),
        });
    };

    const submitForm =
        (url: string, success: string) =>
        (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const form = event.currentTarget;
            post(url, new FormData(form), success, () => form.reset());
        };

    const submitScale = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const name = new FormData(event.currentTarget).get('name');
        post(
            '/portal/assessment-settings/scales',
            { name, bands },
            'ახალი სკალის ვერსია შეიქმნა.',
            () => {
                setShowScaleForm(false);
                setBands([{ min_percent: '0', label: '' }]);
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="შეფასების წესები" />
            <h1 className="mb-2 text-2xl">შეფასების წესები</h1>
            <p className="mb-8 max-w-2xl text-sm text-slate-600">
                აქ დგება სკოლის მიერ დამტკიცებული შეფასების პერიოდები, სკალა და
                კატეგორიები. პლატფორმა არცერთ მათგანს თავად არ ავსებს.
            </p>

            {/* Periods */}
            <section className="mb-10" aria-labelledby="periods-heading">
                <h2 id="periods-heading" className="mb-3 text-lg">
                    შეფასების პერიოდები
                </h2>

                {academicYears.length === 0 ? (
                    <p className="mb-4 rounded-lg bg-slate-50 p-4 text-sm">
                        ჯერ შექმენით სასწავლო წელი („სასწავლო წლები“).
                    </p>
                ) : (
                    <form
                        onSubmit={submitForm(
                            '/portal/assessment-settings/periods',
                            'პერიოდი დაემატა.',
                        )}
                        className={`${sectionCard} mb-4 grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4 lg:items-end`}
                    >
                        <div>
                            <label htmlFor="period-year" className={fieldLabel}>
                                სასწავლო წელი
                            </label>
                            <select
                                id="period-year"
                                name="academic_year_id"
                                required
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {academicYears.map((y) => (
                                    <option key={y.id} value={y.id}>
                                        {y.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="period-name" className={fieldLabel}>
                                დასახელება
                            </label>
                            <Input
                                id="period-name"
                                name="name"
                                required
                                placeholder="მაგ. I სემესტრი"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="period-start"
                                className={fieldLabel}
                            >
                                დაწყება
                            </label>
                            <Input
                                id="period-start"
                                name="starts_on"
                                type="date"
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="period-end" className={fieldLabel}>
                                დასრულება
                            </label>
                            <Input
                                id="period-end"
                                name="ends_on"
                                type="date"
                                required
                            />
                        </div>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <Button type="submit" disabled={sending}>
                                <Plus size={16} /> პერიოდის დამატება
                            </Button>
                        </div>
                    </form>
                )}

                {periods.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                        პერიოდი ჯერ არ არის.
                    </p>
                ) : (
                    <ul className={`${sectionCard} divide-y divide-slate-100`}>
                        {periods.map((p) => (
                            <li key={p.id} className="space-y-3 p-4">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {p.name}{' '}
                                            <span
                                                className={`ml-1 rounded-full px-3 py-1 text-xs font-medium ${
                                                    p.status === 'closed'
                                                        ? 'bg-amber-100 text-amber-900'
                                                        : 'bg-emerald-100 text-emerald-800'
                                                }`}
                                            >
                                                {p.status === 'closed'
                                                    ? 'დახურული'
                                                    : 'ღია'}
                                            </span>
                                        </p>
                                        <p className="text-sm text-slate-500">
                                            {p.academicYearName} ·{' '}
                                            {formatDay(p.startsOn)} –{' '}
                                            {formatDay(p.endsOn)}
                                        </p>
                                    </div>
                                    {p.status === 'closed' ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setReopenId(p.id);
                                                setReopenReason('');
                                            }}
                                        >
                                            ხელახლა გახსნა
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={sending}
                                            onClick={() =>
                                                post(
                                                    `/portal/assessment-settings/periods/${p.id}/close`,
                                                    {},
                                                    'პერიოდი დაიხურა.',
                                                )
                                            }
                                        >
                                            დახურვა
                                        </Button>
                                    )}
                                </div>
                                {reopenId === p.id && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            post(
                                                `/portal/assessment-settings/periods/${p.id}/reopen`,
                                                { reason: reopenReason },
                                                'პერიოდი ხელახლა გაიხსნა.',
                                                () => setReopenId(null),
                                            );
                                        }}
                                        className="flex flex-wrap items-end gap-3"
                                    >
                                        <div className="min-w-[220px] flex-1">
                                            <label
                                                htmlFor={`reopen-${p.id}`}
                                                className={fieldLabel}
                                            >
                                                გახსნის მიზეზი (აუდიტში
                                                ჩაიწერება)
                                            </label>
                                            <Input
                                                id={`reopen-${p.id}`}
                                                value={reopenReason}
                                                onChange={(e) =>
                                                    setReopenReason(
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                                minLength={5}
                                                maxLength={500}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={sending}
                                        >
                                            დადასტურება
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setReopenId(null)}
                                        >
                                            გაუქმება
                                        </Button>
                                    </form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Grade scales */}
            <section className="mb-10" aria-labelledby="scales-heading">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="scales-heading" className="text-lg">
                        შეფასების სკალა
                    </h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowScaleForm((v) => !v)}
                        aria-expanded={showScaleForm}
                    >
                        <Plus size={16} /> ახალი ვერსია
                    </Button>
                </div>
                <p className="mb-4 max-w-2xl text-sm text-slate-600">
                    სკალა პროცენტს საბოლოო ნიშნად გარდაქმნის. შექმნის შემდეგ ის
                    აღარ იცვლება — ახალი წესი ახალ ვერსიად იქმნება და მხოლოდ
                    ახალ შეფასებებზე ვრცელდება.
                </p>

                {showScaleForm && (
                    <form
                        onSubmit={submitScale}
                        className={`${sectionCard} mb-4 space-y-4 p-6`}
                    >
                        <div>
                            <label htmlFor="scale-name" className={fieldLabel}>
                                სკალის დასახელება
                            </label>
                            <Input id="scale-name" name="name" required />
                        </div>
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                ზღვრები (მინიმალური % → ნიშანი). 0%-ის ზღვარი
                                სავალდებულოა.
                            </legend>
                            {bands.map((band, index) => (
                                <div
                                    key={index}
                                    className="flex flex-wrap items-end gap-2"
                                >
                                    <div className="w-28">
                                        <label
                                            htmlFor={`band-min-${index}`}
                                            className="mb-1 block text-xs text-slate-600"
                                        >
                                            მინ. %
                                        </label>
                                        <Input
                                            id={`band-min-${index}`}
                                            type="number"
                                            min={0}
                                            max={100}
                                            step="0.01"
                                            required
                                            value={band.min_percent}
                                            onChange={(e) =>
                                                setBands((all) =>
                                                    all.map((b, i) =>
                                                        i === index
                                                            ? {
                                                                  ...b,
                                                                  min_percent:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : b,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="min-w-[120px] flex-1">
                                        <label
                                            htmlFor={`band-label-${index}`}
                                            className="mb-1 block text-xs text-slate-600"
                                        >
                                            ნიშანი
                                        </label>
                                        <Input
                                            id={`band-label-${index}`}
                                            required
                                            maxLength={32}
                                            value={band.label}
                                            onChange={(e) =>
                                                setBands((all) =>
                                                    all.map((b, i) =>
                                                        i === index
                                                            ? {
                                                                  ...b,
                                                                  label: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : b,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    {bands.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className="h-11 w-11"
                                            aria-label={`ზღვრის წაშლა ${index + 1}`}
                                            onClick={() =>
                                                setBands((all) =>
                                                    all.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 size={16} />
                                        </Button>
                                    )}
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setBands((all) => [
                                        ...all,
                                        { min_percent: '', label: '' },
                                    ])
                                }
                            >
                                <Plus size={16} /> ზღვრის დამატება
                            </Button>
                        </fieldset>
                        <Button type="submit" disabled={sending}>
                            ვერსიის შექმნა და გააქტიურება
                        </Button>
                    </form>
                )}

                {scales.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                        სკალა ჯერ არ არის დამტკიცებული — ქულები და პროცენტები
                        ინახება, საბოლოო ნიშანი კი ცარიელი რჩება.
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {scales.map((scale) => (
                            <li key={scale.id} className={`${sectionCard} p-4`}>
                                <p className="font-medium">
                                    {scale.name} · ვ.{scale.version}
                                    {scale.isActive && (
                                        <span className="ml-2 rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800">
                                            აქტიური
                                        </span>
                                    )}
                                </p>
                                <ul className="mt-2 text-sm text-slate-600">
                                    {scale.bands.map((band) => (
                                        <li key={band.minPercent}>
                                            ≥ {formatScore(band.minPercent)}% →{' '}
                                            <strong>{band.label}</strong>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Categories */}
            <section aria-labelledby="categories-heading">
                <h2 id="categories-heading" className="mb-3 text-lg">
                    კატეგორიები
                </h2>
                <form
                    onSubmit={submitForm(
                        '/portal/assessment-settings/categories',
                        'კატეგორია დაემატა.',
                    )}
                    className="mb-4 flex flex-wrap items-end gap-3"
                >
                    <div className="min-w-[220px] flex-1">
                        <label htmlFor="category-name" className={fieldLabel}>
                            ახალი კატეგორია
                        </label>
                        <Input
                            id="category-name"
                            name="name"
                            required
                            placeholder="მაგ. შემაჯამებელი"
                        />
                    </div>
                    <Button type="submit" disabled={sending}>
                        დამატება
                    </Button>
                </form>
                {categories.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        კატეგორია ჯერ არ არის (არასავალდებულოა).
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {categories.map((c) => (
                            <li
                                key={c.id}
                                className="rounded-full bg-white px-3 py-1 text-sm ring-1 ring-slate-200"
                            >
                                {c.name}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
