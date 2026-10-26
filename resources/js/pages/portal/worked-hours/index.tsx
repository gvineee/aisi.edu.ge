import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Download, Timer } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';

type Row = {
    key: string;
    name: string;
    isPlaceholder: boolean;
    hasAccount: boolean;
    planned: number;
    cancelled: number;
    handedOver: number;
    covered: number;
    taught: number;
    minutes: number;
};

type Props = {
    report: { from: string; to: string; school_days: number; rows: Row[] };
    canManageCalendar: boolean;
    years: { id: number; name: string; startsOn: string; endsOn: string }[];
    breaks: {
        id: number;
        academicYearId: number;
        label: string;
        startsOn: string;
        endsOn: string;
    }[];
};

const hours = (minutes: number) =>
    (minutes / 60).toLocaleString('ka-GE', { maximumFractionDigits: 2 });

/**
 * Worked hours for payroll: per teacher, lessons planned by the timetable
 * in the chosen month, minus cancellations and lessons a substitute took,
 * plus lessons they covered for others. Holidays are managed below.
 */
export default function WorkedHoursIndex({
    report,
    canManageCalendar,
    years,
    breaks,
}: Props) {
    const month = report.from.slice(0, 7);
    const [saving, setSaving] = useState(false);
    const totals = report.rows.reduce(
        (sum, row) => ({
            taught: sum.taught + row.taught,
            minutes: sum.minutes + row.minutes,
        }),
        { taught: 0, minutes: 0 },
    );

    const onError = (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

    const addBreak = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        setSaving(true);
        router.post('/portal/worked-hours/breaks', new FormData(target), {
            preserveScroll: true,
            onSuccess: () => target.reset(),
            onError,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="ნამუშევარი საათები" />
            <h1 className="mb-2 text-2xl">ნამუშევარი საათები</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                ცხრილით დაგეგმილი გაკვეთილები. გამოკლებულია გაუქმებული
                გაკვეთილები და ის, რაც სხვამ ჩაატარა, დამატებულია ჩანაცვლებები.
                ერთობლივი გაკვეთილი რამდენიმე კლასით ერთხელ ითვლება.
                დღესასწაულები და არდადეგები არ ითვლება.
            </p>

            <div className="mb-6 flex flex-wrap items-end gap-4">
                <div>
                    <label
                        htmlFor="month"
                        className="mb-1 block text-sm font-medium"
                    >
                        თვე
                    </label>
                    <Input
                        id="month"
                        type="month"
                        value={month}
                        onChange={(event) =>
                            event.target.value !== '' &&
                            router.get(
                                '/portal/worked-hours',
                                { month: event.target.value },
                                { preserveState: true },
                            )
                        }
                    />
                </div>
                <Button asChild variant="outline">
                    <a href={`/portal/worked-hours/export?month=${month}`}>
                        <Download className="mr-2 h-4 w-4" /> CSV
                        ბუღალტერიისთვის
                    </a>
                </Button>
                <p className="text-sm text-slate-500">
                    {formatDay(report.from)} – {formatDay(report.to)}, სასწავლო
                    დღე: {report.school_days}
                </p>
            </div>

            {report.rows.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <Timer className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        ამ პერიოდში ჩატარებული გაკვეთილი არ არის.
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        საათები ითვლება მხოლოდ მოქმედი (გააქტიურებული)
                        ცხრილიდან.
                    </p>
                </div>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="w-full min-w-[720px] text-sm">
                        <caption className="sr-only">
                            ნამუშევარი საათები {report.from} – {report.to}
                        </caption>
                        <thead className="bg-slate-50 text-left text-xs text-slate-600">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    თანამშრომელი
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 text-right font-medium"
                                >
                                    დაგეგმილი
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 text-right font-medium"
                                >
                                    გაუქმებული
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 text-right font-medium"
                                >
                                    სხვამ ჩაატარა
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 text-right font-medium"
                                >
                                    ჩაანაცვლა
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 text-right font-medium"
                                >
                                    ჩატარებული
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 text-right font-medium"
                                >
                                    საათი
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.map((row) => (
                                <tr
                                    key={row.key}
                                    className="border-t border-slate-100"
                                >
                                    <th
                                        scope="row"
                                        className="px-4 py-2.5 text-left font-medium"
                                    >
                                        {row.name}
                                        {row.isPlaceholder && (
                                            <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                                                ვაკანსია
                                            </span>
                                        )}
                                    </th>
                                    <td className="px-3 py-2.5 text-right">
                                        {row.planned}
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        {row.cancelled}
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        {row.handedOver}
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        {row.covered}
                                    </td>
                                    <td className="px-3 py-2.5 text-right font-semibold">
                                        {row.taught}
                                    </td>
                                    <td className="px-4 py-2.5 text-right">
                                        {hours(row.minutes)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t border-slate-200 bg-slate-50 font-semibold">
                            <tr>
                                <th scope="row" className="px-4 py-3 text-left">
                                    სულ
                                </th>
                                <td colSpan={4} />
                                <td className="px-3 py-3 text-right">
                                    {totals.taught}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {hours(totals.minutes)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}

            <section className="mt-10" aria-labelledby="breaks">
                <h2 id="breaks" className="mb-3 text-lg">
                    დღესასწაულები და არდადეგები
                </h2>
                {breaks.length === 0 ? (
                    <p className="mb-4 text-sm text-slate-500">
                        ჯერ არცერთი არ არის დამატებული. მათ გარეშე ცხრილი ამ
                        დღეებსაც სასწავლოდ თვლის.
                    </p>
                ) : (
                    <ul className="mb-4 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {breaks.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                            >
                                <span>
                                    <span className="font-medium">
                                        {item.label}
                                    </span>{' '}
                                    <span className="text-slate-500">
                                        {item.startsOn === item.endsOn
                                            ? formatDay(item.startsOn)
                                            : `${formatDay(item.startsOn)} – ${formatDay(item.endsOn)}`}
                                    </span>
                                </span>
                                {canManageCalendar && (
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                `/portal/worked-hours/breaks/${item.id}`,
                                                {
                                                    preserveScroll: true,
                                                    onError,
                                                },
                                            )
                                        }
                                    >
                                        წაშლა
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canManageCalendar && years.length > 0 && (
                    <form
                        onSubmit={addBreak}
                        className="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-2 lg:grid-cols-[160px_1fr_160px_160px_auto] lg:items-end"
                    >
                        <div>
                            <label
                                htmlFor="academic_year_id"
                                className="mb-1 block text-sm font-medium"
                            >
                                სასწავლო წელი
                            </label>
                            <select
                                id="academic_year_id"
                                name="academic_year_id"
                                required
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {years.map((year) => (
                                    <option key={year.id} value={year.id}>
                                        {year.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label
                                htmlFor="label"
                                className="mb-1 block text-sm font-medium"
                            >
                                დასახელება
                            </label>
                            <Input
                                id="label"
                                name="label"
                                required
                                maxLength={120}
                                placeholder="მაგ. საახალწლო არდადეგები"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="starts_on"
                                className="mb-1 block text-sm font-medium"
                            >
                                დან
                            </label>
                            <Input
                                id="starts_on"
                                name="starts_on"
                                type="date"
                                required
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="ends_on"
                                className="mb-1 block text-sm font-medium"
                            >
                                მდე
                            </label>
                            <Input
                                id="ends_on"
                                name="ends_on"
                                type="date"
                                required
                            />
                        </div>
                        <Button type="submit" disabled={saving}>
                            დამატება
                        </Button>
                    </form>
                )}
            </section>
        </PortalLayout>
    );
}
