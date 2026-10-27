import { Head, Link, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { PLAN_STATUS, Progress } from '@/components/curriculum/progress';

type Row = {
    key: string;
    classId: number;
    className: string;
    subjectId: number;
    subjectName: string;
    teachers: string[];
    isMine: boolean;
    plan: {
        id: number;
        status: 'draft' | 'approved';
        topics: number;
        covered: number;
        overdue: number;
        plannedHours: number;
        journalLessons: number;
        linkedLessons: number;
    } | null;
};

type Props = {
    years: { id: number; name: string }[];
    yearId: number | null;
    isManager: boolean;
    rows: Row[];
};

/**
 * Every class/subject pair of the year (a teacher: their own) with its
 * plan's state and how much of it the class register shows as covered.
 */
export default function CurriculumIndex({
    years,
    yearId,
    isManager,
    rows,
}: Props) {
    const create = (row: Row) =>
        router.post('/portal/curriculum', {
            school_class_id: row.classId,
            subject_id: row.subjectId,
        });

    const missing = rows.filter((row) => row.plan === null).length;
    const behind = rows.filter((row) => (row.plan?.overdue ?? 0) > 0).length;

    return (
        <PortalLayout>
            <Head title="სასწავლო გეგმები" />
            <h1 className="mb-2 text-2xl">სასწავლო გეგმები</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                თითოეული კლასის და საგნის წლიური თემები. თემა გავლილად ითვლება,
                როცა ჟურნალში გაკვეთილის ჩანაწერს ეს თემა აქვს მითითებული.{' '}
                {isManager
                    ? 'მასწავლებელი ადგენს გეგმას, თქვენ ამტკიცებთ.'
                    : 'გეგმას თქვენ ადგენთ, ადმინისტრაცია ამტკიცებს.'}
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
                            onChange={(event) =>
                                router.get('/portal/curriculum', {
                                    year: event.target.value,
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
                {rows.length > 0 && (
                    <p className="text-sm text-slate-500">
                        სულ {rows.length} · გეგმის გარეშე {missing} · ჩამორჩება{' '}
                        {behind}
                    </p>
                )}
            </div>

            {rows.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <ListChecks className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">კლასი და საგანი ჯერ არ არის.</p>
                    <p className="mt-1 text-sm text-slate-500">
                        სია განრიგიდან და მასწავლებლების დატვირთვიდან ივსება.
                    </p>
                </div>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="w-full min-w-[720px] text-sm">
                        <caption className="sr-only">
                            სასწავლო გეგმები კლასებისა და საგნების მიხედვით
                        </caption>
                        <thead className="bg-slate-50 text-left text-xs text-slate-600">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    კლასი
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    საგანი
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    მასწავლებელი
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    გეგმა
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    გავლილი თემა
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    <span className="sr-only">მოქმედება</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.key}
                                    className="border-t border-slate-100"
                                >
                                    <th
                                        scope="row"
                                        className="px-4 py-2.5 text-left font-medium"
                                    >
                                        {row.className}
                                    </th>
                                    <td className="px-3 py-2.5">
                                        {row.subjectName}
                                    </td>
                                    <td className="px-3 py-2.5 text-slate-600">
                                        {row.teachers.join(', ') || '—'}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        {row.plan ? (
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs ${PLAN_STATUS[row.plan.status].chip}`}
                                            >
                                                {
                                                    PLAN_STATUS[row.plan.status]
                                                        .label
                                                }
                                            </span>
                                        ) : (
                                            <span className="text-slate-400">
                                                არ არის
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        {row.plan && row.plan.topics > 0 ? (
                                            <Progress
                                                covered={row.plan.covered}
                                                total={row.plan.topics}
                                                overdue={row.plan.overdue}
                                            />
                                        ) : (
                                            <span className="text-slate-400">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5 text-right">
                                        {row.plan ? (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <Link
                                                    href={`/portal/curriculum/${row.plan.id}`}
                                                >
                                                    გახსნა
                                                </Link>
                                            </Button>
                                        ) : (
                                            (isManager || row.isMine) && (
                                                <Button
                                                    size="sm"
                                                    onClick={() => create(row)}
                                                >
                                                    შედგენა
                                                </Button>
                                            )
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </PortalLayout>
    );
}
