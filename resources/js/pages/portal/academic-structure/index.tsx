import { Head, router } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { CalendarRange, Plus } from "lucide-react";
import { toast } from "sonner";
import PortalLayout from "@/layouts/portal/portal-layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import * as AcademicStructureController from "@/actions/App/Http/Controllers/Portal/AcademicStructureController";

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
    homeroomTeacherId: number | null;
    homeroomTeacherName: string | null;
};

type StudentRow = {
    id: number;
    name: string;
    firstName: string;
    lastName: string;
    nationalId: string | null;
    hasAccount: boolean;
    schoolClassId: number | null;
    schoolClassName: string | null;
    isActive: boolean;
};

type Props = {
    academicYears: AcademicYear[];
    schoolClasses: SchoolClassRow[];
    students: StudentRow[];
    teachers: { id: number; name: string }[];
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
    teachers,
}: Props) {
    const [showYearForm, setShowYearForm] = useState(false);
    const [showClassForm, setShowClassForm] = useState(false);
    const [showStudentForm, setShowStudentForm] = useState(false);
    const [sending, setSending] = useState(false);
    const [editingStudent, setEditingStudent] = useState<number | null>(null);
    const [studentFilter, setStudentFilter] = useState("");

    const visibleStudents = students.filter(
        (student) =>
            studentFilter === "" ||
            (studentFilter === "inactive"
                ? !student.isActive
                : String(student.schoolClassId) === studentFilter),
    );

    const submitStudentEdit = (
        student: StudentRow,
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        form.set("is_active", form.get("is_active") ? "1" : "0");
        form.set("_method", "put");
        setSending(true);
        router.post(
            AcademicStructureController.updateStudent.url(student.id),
            form,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success("მოსწავლის მონაცემები შენახულია.");
                    setEditingStudent(null);
                },
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? "შემოწმეთ ფორმის ველები.",
                    ),
                onFinish: () => setSending(false),
            },
        );
    };

    const submitYear = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(AcademicStructureController.storeAcademicYear.url(), form, {
            onSuccess: () => {
                toast.success("სასწავლო წელი დაემატა.");
                setShowYearForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error("შემოწმეთ ფორმის ველები."),
            onFinish: () => setSending(false),
        });
    };

    const submitClass = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(AcademicStructureController.storeSchoolClass.url(), form, {
            onSuccess: () => {
                toast.success("კლასი დაემატა.");
                setShowClassForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error("შემოწმეთ ფორმის ველები."),
            onFinish: () => setSending(false),
        });
    };

    const submitStudent = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(AcademicStructureController.storeStudent.url(), form, {
            onSuccess: () => {
                toast.success("მოსწავლე დაემატა.");
                setShowStudentForm(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error("შემოწმეთ ფორმის ველები."),
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
                                        {year.startsOn} – {year.endsOn} ·{" "}
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
                                        {schoolClass.academicYearName} ·{" "}
                                        {schoolClass.studentsCount} მოსწავლე
                                    </p>
                                </div>
                                <label className="text-sm sm:w-64">
                                    <span className="mb-1 block text-xs font-medium text-slate-600">
                                        დამრიგებელი
                                    </span>
                                    <select
                                        className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                                        value={
                                            schoolClass.homeroomTeacherId ?? ""
                                        }
                                        onChange={(event) =>
                                            router.post(
                                                `/portal/academic-structure/school-classes/${schoolClass.id}/homeroom`,
                                                {
                                                    homeroom_teacher_id:
                                                        event.target.value,
                                                },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        toast.success(
                                                            "დამრიგებელი შენახულია.",
                                                        ),
                                                    onError: () =>
                                                        toast.error(
                                                            "ვერ შეინახა.",
                                                        ),
                                                },
                                            )
                                        }
                                    >
                                        <option value="">— არ არის —</option>
                                        {teachers.map((teacher) => (
                                            <option
                                                key={teacher.id}
                                                value={teacher.id}
                                            >
                                                {teacher.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
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
                                        {schoolClass.name} ·{" "}
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

                {students.length > 0 && (
                    <div className="mb-3 sm:w-72">
                        <label
                            htmlFor="student-filter"
                            className="mb-1 block text-sm font-medium"
                        >
                            ფილტრი
                        </label>
                        <select
                            id="student-filter"
                            value={studentFilter}
                            onChange={(event) =>
                                setStudentFilter(event.target.value)
                            }
                            className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                        >
                            <option value="">ყველა მოსწავლე</option>
                            {schoolClasses.map((schoolClass) => (
                                <option
                                    key={schoolClass.id}
                                    value={schoolClass.id}
                                >
                                    {schoolClass.name} ·{" "}
                                    {schoolClass.academicYearName}
                                </option>
                            ))}
                            <option value="inactive">არააქტიური</option>
                        </select>
                    </div>
                )}

                {visibleStudents.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <p className="font-medium">
                            {students.length === 0
                                ? "მოსწავლე ჯერ არ არის დამატებული."
                                : "ამ ფილტრით მოსწავლე ვერ მოიძებნა."}
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {visibleStudents.map((student) => (
                            <li key={student.id} className="p-4">
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p className="font-medium">
                                            {student.name}
                                            {!student.isActive && (
                                                <span className="ml-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                                                    არააქტიური
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-sm text-slate-500">
                                            {student.schoolClassName ??
                                                "კლასის გარეშე"}
                                            {student.nationalId &&
                                                ` · ${student.nationalId}`}
                                            {student.hasAccount
                                                ? " · პორტალის ანგარიშით"
                                                : " · ანგარიშის გარეშე"}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11"
                                        aria-expanded={
                                            editingStudent === student.id
                                        }
                                        onClick={() =>
                                            setEditingStudent(
                                                editingStudent === student.id
                                                    ? null
                                                    : student.id,
                                            )
                                        }
                                    >
                                        რედაქტირება
                                    </Button>
                                </div>

                                {editingStudent === student.id && (
                                    <form
                                        onSubmit={(event) =>
                                            submitStudentEdit(student, event)
                                        }
                                        className="mt-4 space-y-4 rounded-lg border border-slate-200 p-4"
                                    >
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label
                                                    htmlFor={`st-first-${student.id}`}
                                                    className="mb-1 block text-sm font-medium"
                                                >
                                                    სახელი
                                                </label>
                                                <Input
                                                    id={`st-first-${student.id}`}
                                                    name="first_name"
                                                    defaultValue={
                                                        student.firstName
                                                    }
                                                    required
                                                    className="h-11"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    htmlFor={`st-last-${student.id}`}
                                                    className="mb-1 block text-sm font-medium"
                                                >
                                                    გვარი
                                                </label>
                                                <Input
                                                    id={`st-last-${student.id}`}
                                                    name="last_name"
                                                    defaultValue={
                                                        student.lastName
                                                    }
                                                    required
                                                    className="h-11"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    htmlFor={`st-nid-${student.id}`}
                                                    className="mb-1 block text-sm font-medium"
                                                >
                                                    პირადი ნომერი
                                                </label>
                                                <Input
                                                    id={`st-nid-${student.id}`}
                                                    name="national_id"
                                                    defaultValue={
                                                        student.nationalId ?? ""
                                                    }
                                                    className="h-11"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    htmlFor={`st-class-${student.id}`}
                                                    className="mb-1 block text-sm font-medium"
                                                >
                                                    კლასი (გადაყვანა)
                                                </label>
                                                <select
                                                    id={`st-class-${student.id}`}
                                                    name="school_class_id"
                                                    required
                                                    defaultValue={
                                                        student.schoolClassId ??
                                                        ""
                                                    }
                                                    className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                                                >
                                                    <option value="">
                                                        აირჩიეთ კლასი
                                                    </option>
                                                    {schoolClasses.map(
                                                        (schoolClass) => (
                                                            <option
                                                                key={
                                                                    schoolClass.id
                                                                }
                                                                value={
                                                                    schoolClass.id
                                                                }
                                                            >
                                                                {
                                                                    schoolClass.name
                                                                }{" "}
                                                                ·{" "}
                                                                {
                                                                    schoolClass.academicYearName
                                                                }
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                        </div>
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="is_active"
                                                value="1"
                                                defaultChecked={
                                                    student.isActive
                                                }
                                                className="h-4 w-4"
                                            />
                                            აქტიური მოსწავლე (მოხსნისას მოსწავლე
                                            სიებიდან ქრება, ისტორია ინახება)
                                        </label>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="submit"
                                                disabled={sending}
                                                className="min-h-11"
                                            >
                                                შენახვა
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={() =>
                                                    setEditingStudent(null)
                                                }
                                            >
                                                გაუქმება
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
