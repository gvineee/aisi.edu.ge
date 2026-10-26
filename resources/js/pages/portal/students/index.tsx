import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { GraduationCap, Plus } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Student = {
    id: number;
    name: string;
    className: string | null;
    isActive: boolean;
    hasAccount: boolean;
    groups: string[];
    guardians: string[];
};

type Props = {
    years: { id: number; name: string }[];
    yearId: number | null;
    classes: { id: number; name: string }[];
    filters: { class: number | null; q: string; inactive: boolean };
    total: number;
    students: Student[];
};

/**
 * Every pupil of a year in one list: filter by class, search by name, see
 * guardians and groups, open a pupil's progress, portfolio or report
 * cards. Pupils are added and moved between classes in the academic
 * structure, where classes are managed.
 */
export default function StudentsIndex({
    years,
    yearId,
    classes,
    filters,
    total,
    students,
}: Props) {
    const [q, setQ] = useState(filters.q);

    const go = (params: Record<string, string | number | boolean | null>) =>
        router.get(
            '/portal/students',
            {
                year: yearId,
                class: filters.class,
                q: filters.q,
                inactive: filters.inactive ? 1 : null,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );

    const search = (event: FormEvent) => {
        event.preventDefault();
        go({ q });
    };

    return (
        <PortalLayout>
            <Head title="მოსწავლეები" />
            <div className="mb-2 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">მოსწავლეები</h1>
                <Button asChild variant="outline">
                    <Link href="/portal/academic-structure">
                        <Plus className="mr-2 h-4 w-4" /> დამატება და კლასების
                        მართვა
                    </Link>
                </Button>
            </div>
            <p className="mb-6 text-sm text-slate-600">
                აქტიური მოსწავლე: {total}
            </p>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
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
                                go({ year: e.target.value, class: null })
                            }
                            className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
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
                        htmlFor="class"
                        className="mb-1 block text-sm font-medium"
                    >
                        კლასი
                    </label>
                    <select
                        id="class"
                        value={filters.class ?? ''}
                        onChange={(e) => go({ class: e.target.value || null })}
                        className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                    >
                        <option value="">ყველა კლასი</option>
                        {classes.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </div>
                <form
                    onSubmit={search}
                    className="flex items-end gap-2 lg:col-span-2"
                >
                    <div className="flex-1">
                        <label
                            htmlFor="q"
                            className="mb-1 block text-sm font-medium"
                        >
                            ძებნა
                        </label>
                        <Input
                            id="q"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="სახელი ან გვარი"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        ძებნა
                    </Button>
                </form>
            </div>

            <label className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={filters.inactive}
                    onChange={(e) => go({ inactive: e.target.checked })}
                />
                წასული მოსწავლეების ჩვენებაც
            </label>

            {students.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <GraduationCap className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">მოსწავლე ვერ მოიძებნა.</p>
                    <p className="mt-1 text-sm text-slate-500">
                        მოსწავლეები EduPage-იდან გადმოტანის შემდეგ აქ
                        გამოჩნდებიან. მანამდე შეგიძლიათ ხელით დაამატოთ „დამატება
                        და კლასების მართვიდან“.
                    </p>
                </div>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="w-full min-w-[720px] text-sm">
                        <caption className="sr-only">მოსწავლეების სია</caption>
                        <thead className="bg-slate-50 text-left text-xs text-slate-600">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    მოსწავლე
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    კლასი
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    ჯგუფები
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    მშობლები
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    გახსნა
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {students.map((student) => (
                                <tr
                                    key={student.id}
                                    className="border-t border-slate-100 align-top"
                                >
                                    <th
                                        scope="row"
                                        className="px-4 py-2.5 text-left font-medium"
                                    >
                                        {student.name}
                                        {!student.isActive && (
                                            <span className="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-700">
                                                წასული
                                            </span>
                                        )}
                                    </th>
                                    <td className="px-3 py-2.5">
                                        {student.className ?? '—'}
                                    </td>
                                    <td className="px-3 py-2.5 text-slate-600">
                                        {student.groups.join(', ') || '—'}
                                    </td>
                                    <td className="px-3 py-2.5 text-slate-600">
                                        {student.guardians.length > 0 ? (
                                            student.guardians.join(', ')
                                        ) : (
                                            <span className="text-amber-800">
                                                მიუბმელი
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <span className="flex flex-wrap gap-x-3 gap-y-1">
                                            <Link
                                                href={`/portal/students/${student.id}/progress`}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                პროგრესი
                                            </Link>
                                            <Link
                                                href={`/portal/students/${student.id}/portfolio`}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                პორტფოლიო
                                            </Link>
                                            <Link
                                                href={`/portal/students/${student.id}/report-cards`}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                ტაბელები
                                            </Link>
                                        </span>
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
