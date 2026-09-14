import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CalendarRange, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as AcademicStructureController from '@/actions/App/Http/Controllers/Portal/AcademicStructureController';

type AcademicYear = {
    id: number;
    name: string;
    startsOn: string;
    endsOn: string;
    isCurrent: boolean;
    schoolClassesCount: number;
};

type SchoolClassRow = {
    id: number;
    name: string;
    academicYearId: number;
    academicYearName: string;
    studentsCount: number;
};

type StudentRow = {
    id: number;
    name: string;
    nationalId: string | null;
    schoolClassId: number | null;
    schoolClassName: string | null;
    isActive: boolean;
};

type Props = {
    academicYears: AcademicYear[];
    schoolClasses: SchoolClassRow[];
    students: StudentRow[];
};

/**
 * Sets up the school's own structure: academic years → classes → student
 * roster. Without this screen, a teacher invitation had no class to attach
 * to and an Assignment had no class/roster to work against — this is the
 * production root blocker the Assignments module verification found.
 */
export default function AcademicStructureIndex({
    academicYears,
    schoolClasses,
    students,
}: Props) {
    const [showYearForm, setShowYearForm] = useState(false);
    const [showClassForm, setShowClassForm] = useState(false);
    const [showStudentForm, setShowStudentForm] = useState(false);
    const [sending, setSending] = useState(false);

    const submitYear = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(AcademicStructureController.storeAcademicYear.url(), form, {
            onSuccess: () => {
                toast.success('სასწავლო წელი დაემატა.');
                setShowYearForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSending(false),
        });
    };

    const submitClass = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(AcademicStructureController.storeSchoolClass.url(), form, {
            onSuccess: () => {
                toast.success('კლასი დაემატა.');
                setShowClassForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSending(false),
        });
    };

    const submitStudent = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(AcademicStructureController.storeStudent.url(), form, {
            onSuccess: () => {
                toast.success('მოსწავლე დაემატა.');
                setShowStudentForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSending(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="სასწავლო წლები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">სასწავლო წლები და კლასები</h1>
            </div>

            {/* Academic years */}
            <section className="mb-8">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg">სასწავლო წლები</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowYearForm((v) => !v)}
                    >
                        <Plus size={16} /> ახალი სასწავლო წელი
                    </Button>
                </div>

                {showYearForm && (
                    <form
                        onSubmit={submitYear}
                        className="mb-4 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                    >
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                დასახელება
                            </label>
                            <Input
                                name="name"
                                placeholder="მაგ. 2026-2027"
                                required
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დაწყების თარიღი
                                </label>
                                <Input name="starts_on" type="date" required />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    დასრულების თარიღი
                                </label>
                                <Input name="ends_on" type="date" required />
                            </div>
                        </div>
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_current"
                                value="1"
                                defaultChecked={academicYears.length === 0}
                                className="h-4 w-4"
                            />
                            მიმდინარე სასწავლო წელი
                        </label>
                        <Button type="submit" disabled={sending}>
                            შენახვა
                        </Button>
                    </form>
                )}

                {academicYears.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <CalendarRange className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">
                            სასწავლო წელი ჯერ არ არის დამატებული.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {academicYears.map((year) => (
                            <li
                                key={year.id}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {year.name}
                                        {year.isCurrent && (
                                            <span className="ml-2 inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800">
                                                მიმდინარე
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {year.startsOn} – {year.endsOn} ·{' '}
                                        {year.schoolClassesCount} კლასი
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Classes */}
            <section className="mb-8">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg">კლასები</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowClassForm((v) => !v)}
                        disabled={academicYears.length === 0}
                    >
                        <Plus size={16} /> ახალი კლასი
                    </Button>
                </div>

                {academicYears.length === 0 && (
                    <p className="mb-4 text-sm text-slate-500">
                        კლასის დასამატებლად ჯერ საჭიროა სასწავლო წელი.
                    </p>
                )}

                {showClassForm && (
                    <form
                        onSubmit={submitClass}
                        className="mb-4 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                    >
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                სასწავლო წელი
                            </label>
                            <select
                                name="academic_year_id"
                                required
                                className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                            >
                                <option value="">აირჩიეთ სასწავლო წელი</option>
                                {academicYears.map((year) => (
                                    <option key={year.id} value={year.id}>
                                        {year.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                კლასის დასახელება
                            </label>
                            <Input name="name" placeholder="მაგ. VI" required />
                        </div>
                        <Button type="submit" disabled={sending}>
                            შენახვა
                        </Button>
                    </form>
                )}

                {schoolClasses.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <p className="font-medium">
                            კლასი ჯერ არ არის დამატებული.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {schoolClasses.map((schoolClass) => (
                            <li
                                key={schoolClass.id}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {schoolClass.name}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {schoolClass.academicYearName} ·{' '}
                                        {schoolClass.studentsCount} მოსწავლე
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Students */}
            <section>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg">მოსწავლეები</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowStudentForm((v) => !v)}
                        disabled={schoolClasses.length === 0}
                    >
                        <Plus size={16} /> ახალი მოსწავლე
                    </Button>
                </div>

                {schoolClasses.length === 0 && (
                    <p className="mb-4 text-sm text-slate-500">
                        მოსწავლის დასამატებლად ჯერ საჭიროა კლასი.
                    </p>
                )}

                {showStudentForm && (
                    <form
                        onSubmit={submitStudent}
                        className="mb-4 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                    >
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                კლასი
                            </label>
                            <select
                                name="school_class_id"
                                required
                                className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                            >
                                <option value="">აირჩიეთ კლასი</option>
                                {schoolClasses.map((schoolClass) => (
                                    <option
                                        key={schoolClass.id}
                                        value={schoolClass.id}
                                    >
                                        {schoolClass.name} ·{' '}
                                        {schoolClass.academicYearName}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    სახელი
                                </label>
                                <Input name="first_name" required />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    გვარი
                                </label>
                                <Input name="last_name" required />
                            </div>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                პირადი ნომერი (არასავალდებულო)
                            </label>
                            <Input name="national_id" />
                        </div>
                        <Button type="submit" disabled={sending}>
                            შენახვა
                        </Button>
                    </form>
                )}

                {students.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <p className="font-medium">
                            მოსწავლე ჯერ არ არის დამატებული.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {students.map((student) => (
                            <li
                                key={student.id}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {student.name}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {student.schoolClassName ??
                                            'კლასის გარეშე'}
                                        {student.nationalId &&
                                            ` · ${student.nationalId}`}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
