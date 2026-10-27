import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChartColumn } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDay } from '@/lib/assessment';

type Attendance = {
    marked: number;
    attended: number;
    absent: number;
    rate: number | null;
};
type Results = { assessments: number; results: number; average: number | null };
type Curriculum = {
    plans: number;
    topics: number;
    covered: number;
    overdue: number;
};

type Props = {
    years: { id: number; name: string }[];
    yearId: number | null;
    periods: { id: number; name: string }[];
    periodId: number | null;
    range: { from: string; to: string };
    computedAt: string;
    classes: {
        classId: number;
        className: string;
        students: number;
        attendance: Attendance;
        results: Results;
        curriculum: Curriculum;
    }[];
    selected: {
        id: number;
        name: string;
        subjects: {
            subjectId: number;
            subjectName: string;
            teachers: string[];
            attendance: Attendance;
            results: Results;
            curriculum: Curriculum;
        }[];
        students: {
            id: number;
            name: string;
            attendance: Attendance;
            average: number | null;
            results: number;
        }[];
    } | null;
};

const percent = (value: number | null) =>
    value === null
        ? '—'
        : `${value.toLocaleString('ka-GE', { maximumFractionDigits: 1 })}%`;

const curriculumText = (c: Curriculum) =>
    c.topics === 0
        ? '—'
        : `${c.covered}/${c.topics}${c.overdue > 0 ? ` · ჩამორჩება ${c.overdue}` : ''}`;

const th = 'px-3 py-3 font-medium';
const td = 'px-3 py-2.5';

/**
 * Classes side by side for a period, and one class opened up by subject
 * and pupil. Figures are recorded data only; definitions are under the
 * tables.
 */
export default function AcademicOverviewIndex({
    years,
    yearId,
    periods,
    periodId,
    range,
    computedAt,
    classes,
    selected,
}: Props) {
    const query = (change: Record<string, number | string | null>) => {
        const params: Record<string, string> = {};
        const next = {
            year: yearId,
            period: periodId,
            class: selected?.id ?? null,
            ...change,
        };
        Object.entries(next).forEach(([key, value]) => {
            if (value !== null && String(value) !== '') params[key] = String(value);
        });

        return params;
    };
    const go = (change: Record<string, number | string | null>) =>
        router.get('/portal/academic-overview', query(change), {
            preserveScroll: true,
        });

    return (
        <PortalLayout>
            <Head title="აკადემიური მიმოხილვა" />
            <h1 className="mb-2 text-2xl">აკადემიური მიმოხილვა</h1>
            <p className="mb-6 text-sm text-slate-500">
                {formatDay(range.from)} – {formatDay(range.to)} · გამოთვლილია{' '}
                {new Date(computedAt).toLocaleString('ka-GE', {
                    timeZone: 'Asia/Tbilisi',
                })}
            </p>

            <div className="mb-6 flex flex-wrap items-end gap-4">
                {years.length > 1 && (
                    <div>
                        <label
                            htmlFor="year"
                            className="mb-1 block text-sm font-medium"
                        >
                            სასწავლო წელი
                        </label>
                        <select
                            id="year"
                            value={yearId ?? ''}
                            onChange={(e) =>
                                go({
                                    year: e.target.value,
                                    period: null,
                                    class: null,
                                })
                            }
                            className="h-11 rounded-md border border-slate-300 bg-white px-3 text-sm"
                        >
                            {years.map((year) => (
                                <option key={year.id} value={year.id}>
                                    {year.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <div>
                    <label
                        htmlFor="period"
                        className="mb-1 block text-sm font-medium"
                    >
                        პერიოდი
                    </label>
                    <select
                        id="period"
                        value={periodId ?? ''}
                        onChange={(e) => go({ period: e.target.value || null })}
                        className="h-11 rounded-md border border-slate-300 bg-white px-3 text-sm"
                    >
                        <option value="">მთელი წელი (დღემდე)</option>
                        {periods.map((period) => (
                            <option key={period.id} value={period.id}>
                                {period.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {selected ? (
                <>
                    <button
                        type="button"
                        onClick={() => go({ class: null })}
                        className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
                    >
                        <ArrowLeft size={16} /> ყველა კლასი
                    </button>
                    <h2 className="mb-3 text-xl">
                        {selected.name} — საგნების მიხედვით
                    </h2>
                    <div className="mb-8 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                        <table className="w-full min-w-[720px] text-sm">
                            <caption className="sr-only">
                                {selected.name}: საგნები
                            </caption>
                            <thead className="bg-slate-50 text-left text-xs text-slate-600">
                                <tr>
                                    <th scope="col" className={th}>
                                        საგანი
                                    </th>
                                    <th scope="col" className={th}>
                                        მასწავლებელი
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        დასწრება
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        გაცდენა
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        შეფასება (საშ.)
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        დავალება / შეფ.
                                    </th>
                                    <th scope="col" className={th}>
                                        გეგმა
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {selected.subjects.map((row) => (
                                    <tr
                                        key={row.subjectId}
                                        className="border-t border-slate-100"
                                    >
                                        <th
                                            scope="row"
                                            className={`${td} text-left font-medium`}
                                        >
                                            {row.subjectName}
                                        </th>
                                        <td className={`${td} text-slate-600`}>
                                            {row.teachers.join(', ') || '—'}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {percent(row.attendance.rate)}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {row.attendance.absent}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {percent(row.results.average)}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {row.results.assessments}
                                        </td>
                                        <td className={td}>
                                            {curriculumText(row.curriculum)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="mb-1 text-xl">მოსწავლეები</h2>
                    <p className="mb-3 text-sm text-slate-500">
                        დალაგებულია დასწრებით, ყველაზე დაბლიდან.
                    </p>
                    <div className="mb-8 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                        <table className="w-full min-w-[560px] text-sm">
                            <caption className="sr-only">
                                {selected.name}: მოსწავლეები
                            </caption>
                            <thead className="bg-slate-50 text-left text-xs text-slate-600">
                                <tr>
                                    <th scope="col" className={th}>
                                        მოსწავლე
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        დასწრება
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        გაცდენილი გაკვეთილი
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        შეფასება (საშ.)
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${th} text-right`}
                                    >
                                        შეფასებები
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {selected.students.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-slate-100"
                                    >
                                        <th
                                            scope="row"
                                            className={`${td} text-left font-medium`}
                                        >
                                            <Link
                                                href={`/portal/students/${row.id}/progress`}
                                                className="hover:underline"
                                            >
                                                {row.name}
                                            </Link>
                                        </th>
                                        <td className={`${td} text-right`}>
                                            {percent(row.attendance.rate)}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {row.attendance.absent}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {percent(row.average)}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            {row.results}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            ) : classes.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <ChartColumn className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">ამ წელს კლასები არ არის.</p>
                </div>
            ) : (
                <div className="mb-8 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="w-full min-w-[720px] text-sm">
                        <caption className="sr-only">
                            კლასები: დასწრება, შეფასება, გეგმა
                        </caption>
                        <thead className="bg-slate-50 text-left text-xs text-slate-600">
                            <tr>
                                <th scope="col" className={th}>
                                    კლასი
                                </th>
                                <th scope="col" className={`${th} text-right`}>
                                    მოსწავლე
                                </th>
                                <th scope="col" className={`${th} text-right`}>
                                    დასწრება
                                </th>
                                <th scope="col" className={`${th} text-right`}>
                                    გაცდენა
                                </th>
                                <th scope="col" className={`${th} text-right`}>
                                    შეფასება (საშ.)
                                </th>
                                <th scope="col" className={`${th} text-right`}>
                                    გამოქვ. შეფასება
                                </th>
                                <th scope="col" className={th}>
                                    გეგმა (თემა)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {classes.map((row) => (
                                <tr
                                    key={row.classId}
                                    className="border-t border-slate-100"
                                >
                                    <th
                                        scope="row"
                                        className={`${td} text-left font-medium`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                go({ class: row.classId })
                                            }
                                            className="min-h-11 hover:underline"
                                        >
                                            {row.className}
                                        </button>
                                    </th>
                                    <td className={`${td} text-right`}>
                                        {row.students}
                                    </td>
                                    <td className={`${td} text-right`}>
                                        {percent(row.attendance.rate)}
                                    </td>
                                    <td className={`${td} text-right`}>
                                        {row.attendance.absent}
                                    </td>
                                    <td className={`${td} text-right`}>
                                        {percent(row.results.average)}
                                    </td>
                                    <td className={`${td} text-right`}>
                                        {row.results.assessments}
                                    </td>
                                    <td className={td}>
                                        {curriculumText(row.curriculum)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <section
                aria-labelledby="definitions"
                className="max-w-3xl text-sm text-slate-600"
            >
                <h2
                    id="definitions"
                    className="mb-2 text-base font-medium text-slate-900"
                >
                    როგორ ითვლება
                </h2>
                <ul className="list-disc space-y-1 pl-5">
                    <li>
                        <strong>დასწრება</strong> — მონიშნული
                        გაკვეთილ-დასწრებებიდან „დაესწრო“ და „დააგვიანა“ ერთად,
                        პერიოდის დღეებში; „—“ ნიშნავს, რომ დასწრება არ
                        მონიშნულა.
                    </li>
                    <li>
                        <strong>გაცდენა</strong> — „არ დაესწრო“ ჩანაწერების
                        რაოდენობა (გაკვეთილებში).
                    </li>
                    <li>
                        <strong>შეფასება (საშ.)</strong> — პერიოდის მხოლოდ
                        გამოქვეყნებული შეფასებების პროცენტების უწონო საშუალო.
                        საბოლოო ნიშანი არ არის.
                    </li>
                    <li>
                        <strong>გეგმა</strong> — სასწავლო გეგმებში გავლილი თემა
                        / ყველა თემა; „ჩამორჩება“ — თემა, რომლის ვადა გავიდა და
                        ჟურნალში ჯერ არ ჩანს.
                    </li>
                </ul>
            </section>
        </PortalLayout>
    );
}
