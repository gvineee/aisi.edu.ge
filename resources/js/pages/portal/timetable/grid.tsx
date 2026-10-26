import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, TriangleAlert } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type Item = {
    subject: string;
    subjectFull: string;
    classes: string[];
    group: string | null;
    teacher: string;
    room: string | null;
};

type Props = {
    versions: {
        id: number;
        label: string;
        status: 'draft' | 'active' | 'archived';
        yearName: string;
        validFrom: string;
        validTo: string | null;
    }[];
    versionId: number | null;
    by: 'class' | 'teacher' | 'room';
    options: { id: number; name: string }[];
    selectedId: number | null;
    periods: { number: number; startsAt: string; endsAt: string }[];
    cells: Record<string, { conflict: boolean; items: Item[] }>;
    summary: {
        lessons: number;
        rows: number;
        shown: number;
        conflicts: number;
    };
};

const DAYS = ['ორშაბათი', 'სამშაბათი', 'ოთხშაბათი', 'ხუთშაბათი', 'პარასკევი'];

const STATUS: Record<Props['versions'][number]['status'], string> = {
    draft: 'მონახაზი',
    active: 'მოქმედი',
    archived: 'არქივი',
};

const BY: Record<Props['by'], string> = {
    class: 'კლასი',
    teacher: 'მასწავლებელი',
    room: 'ოთახი',
};

/**
 * A timetable version as a week grid for one class, teacher or room — for
 * checking an imported or generated timetable before activating it. On a
 * phone the days become stacked cards instead of a wide table.
 */
export default function TimetableGrid({
    versions,
    versionId,
    by,
    options,
    selectedId,
    periods,
    cells,
    summary,
}: Props) {
    const go = (params: Record<string, string | number | null>) =>
        router.get(
            '/portal/timetable/grid',
            { version: versionId, by, id: selectedId, ...params },
            { preserveState: true, preserveScroll: true },
        );

    const cell = (day: number, period: number) => cells[`${day}|${period}`];

    const renderItems = (entry: Props['cells'][string] | undefined) =>
        entry?.items.map((item, index) => (
            <div key={index} className="leading-tight">
                <p className="font-medium" title={item.subjectFull}>
                    {item.subject}
                </p>
                <p className="text-xs text-slate-600">
                    {by !== 'class' && item.classes.join(', ')}
                    {by !== 'class' && item.group && ' · '}
                    {item.group}
                </p>
                <p className="text-xs text-slate-500">
                    {by !== 'teacher' && item.teacher}
                    {by !== 'room' && item.room && ` · ${item.room}`}
                </p>
            </div>
        ));

    return (
        <PortalLayout>
            <Head title="ცხრილის ხედი" />
            <div className="mb-2 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ცხრილის ხედი</h1>
                <Link
                    href="/portal/timetable"
                    className="min-h-11 text-sm underline-offset-4 hover:underline"
                >
                    ← განრიგის მართვა
                </Link>
            </div>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                აირჩიეთ ცხრილის ვერსია და კლასი, მასწავლებელი ან ოთახი.
                მონახაზის გადახედვის შემდეგ ვერსია „განრიგის მართვაში“
                აქტიურდება. მხოლოდ მოქმედ ვერსიას ხედავენ მოსწავლეები და
                მშობლები.
            </p>

            {versions.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <CalendarClock className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">ცხრილის ვერსია ჯერ არ არის.</p>
                </div>
            ) : (
                <>
                    <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label
                                htmlFor="version"
                                className="mb-1 block text-sm font-medium"
                            >
                                ვერსია
                            </label>
                            <select
                                id="version"
                                value={versionId ?? ''}
                                onChange={(event) =>
                                    go({
                                        version: event.target.value,
                                        id: null,
                                    })
                                }
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {versions.map((version) => (
                                    <option key={version.id} value={version.id}>
                                        {version.label} (
                                        {STATUS[version.status]},{' '}
                                        {version.yearName})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label
                                htmlFor="by"
                                className="mb-1 block text-sm font-medium"
                            >
                                ხედი
                            </label>
                            <select
                                id="by"
                                value={by}
                                onChange={(event) =>
                                    go({ by: event.target.value, id: null })
                                }
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {(Object.keys(BY) as Props['by'][]).map(
                                    (key) => (
                                        <option key={key} value={key}>
                                            {BY[key]}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <div>
                            <label
                                htmlFor="entity"
                                className="mb-1 block text-sm font-medium"
                            >
                                {BY[by]}
                            </label>
                            <select
                                id="entity"
                                value={selectedId ?? ''}
                                onChange={(event) =>
                                    go({ id: event.target.value })
                                }
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {options.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <p className="mb-4 text-sm text-slate-600">
                        ვერსიაში {summary.lessons} გაკვეთილი კვირაში · არჩეულზე{' '}
                        {summary.shown}
                        {summary.conflicts > 0 && (
                            <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-red-800">
                                <TriangleAlert
                                    className="h-3.5 w-3.5"
                                    aria-hidden
                                />{' '}
                                გადაფარვა: {summary.conflicts}
                            </span>
                        )}
                    </p>

                    {/* Desktop: days × periods */}
                    <div className="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white md:block">
                        <table className="w-full min-w-[760px] table-fixed text-sm">
                            <caption className="sr-only">კვირის ცხრილი</caption>
                            <thead className="bg-slate-50 text-xs text-slate-600">
                                <tr>
                                    <th
                                        scope="col"
                                        className="w-24 px-3 py-2 text-left font-medium"
                                    >
                                        დღე
                                    </th>
                                    {periods.map((period) => (
                                        <th
                                            key={period.number}
                                            scope="col"
                                            className="px-2 py-2 text-left font-medium"
                                        >
                                            {period.number}
                                            <span className="block font-normal text-slate-500">
                                                {period.startsAt}–
                                                {period.endsAt}
                                            </span>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {DAYS.map((name, index) => (
                                    <tr
                                        key={name}
                                        className="border-t border-slate-100 align-top"
                                    >
                                        <th
                                            scope="row"
                                            className="px-3 py-2 text-left font-medium"
                                        >
                                            {name}
                                        </th>
                                        {periods.map((period) => {
                                            const entry = cell(
                                                index + 1,
                                                period.number,
                                            );
                                            return (
                                                <td
                                                    key={period.number}
                                                    className={`space-y-1 border-l border-slate-100 px-2 py-2 ${entry?.conflict ? 'bg-red-50' : ''}`}
                                                >
                                                    {renderItems(entry)}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Phone: one card per day */}
                    <div className="space-y-3 md:hidden">
                        {DAYS.map((name, index) => {
                            const day = periods.filter((period) =>
                                cell(index + 1, period.number),
                            );
                            return (
                                <section
                                    key={name}
                                    className="rounded-xl border border-slate-200 bg-white p-4"
                                >
                                    <h2 className="mb-2 font-medium">{name}</h2>
                                    {day.length === 0 ? (
                                        <p className="text-sm text-slate-500">
                                            გაკვეთილი არ არის.
                                        </p>
                                    ) : (
                                        <ol className="space-y-2">
                                            {day.map((period) => {
                                                const entry = cell(
                                                    index + 1,
                                                    period.number,
                                                );
                                                return (
                                                    <li
                                                        key={period.number}
                                                        className={`flex gap-3 rounded-lg p-2 text-sm ${entry?.conflict ? 'bg-red-50' : 'bg-slate-50'}`}
                                                    >
                                                        <span className="w-14 shrink-0 text-xs text-slate-500">
                                                            {period.number}.{' '}
                                                            {period.startsAt}
                                                        </span>
                                                        <div className="space-y-1">
                                                            {renderItems(entry)}
                                                        </div>
                                                    </li>
                                                );
                                            })}
                                        </ol>
                                    )}
                                </section>
                            );
                        })}
                    </div>
                </>
            )}
        </PortalLayout>
    );
}
