import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft, Plus, Users } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as AcademicStructureController from '@/actions/App/Http/Controllers/Portal/AcademicStructureController';
import * as SchoolClassProfileController from '@/actions/App/Http/Controllers/Portal/SchoolClassProfileController';

type Option = { id: number; name: string };

type Group = { id: number; name: string; studentIds: number[] };

type Enrollment = {
    id: number;
    studentName: string;
    startsOn: string;
    endsOn: string | null;
    status: string;
};

type Props = {
    schoolClass: {
        id: number;
        name: string;
        academicYearName: string;
        gradeLevel: number | null;
        shortName: string | null;
        homeRoomId: number | null;
        homeroomTeacherName: string | null;
        coHomeroomIds: number[];
    };
    students: Option[];
    groups: Group[];
    teachers: Option[];
    rooms: Option[];
    enrollments: Enrollment[];
};

const ENROLLMENT_STATUS: Record<string, string> = {
    active: 'აქტიური',
    transferred: 'გადაყვანილი',
    left: 'გასული',
    completed: 'დასრულებული',
};

const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

/**
 * One class's profile: grade level, short name, home room, second homeroom
 * teacher, teaching groups (split lessons) and the dated enrollment history.
 */
export default function SchoolClassProfile({
    schoolClass,
    students,
    groups,
    teachers,
    rooms,
    enrollments,
}: Props) {
    const [sending, setSending] = useState(false);
    const [editingGroup, setEditingGroup] = useState<number | 'new' | null>(
        null,
    );

    const onError = (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

    const submitProfile = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        form.set('_method', 'put');
        setSending(true);
        router.post(
            SchoolClassProfileController.update.url(schoolClass.id),
            form,
            {
                preserveScroll: true,
                onError,
                onFinish: () => setSending(false),
            },
        );
    };

    const submitGroup = (
        group: Group | null,
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const url = group
            ? SchoolClassProfileController.updateGroup.url({
                  schoolClass: schoolClass.id,
                  studentGroup: group.id,
              })
            : SchoolClassProfileController.storeGroup.url(schoolClass.id);
        if (group) form.set('_method', 'put');
        setSending(true);
        router.post(url, form, {
            preserveScroll: true,
            onSuccess: () => setEditingGroup(null),
            onError,
            onFinish: () => setSending(false),
        });
    };

    const deleteGroup = (group: Group) => {
        router.delete(
            SchoolClassProfileController.destroyGroup.url({
                schoolClass: schoolClass.id,
                studentGroup: group.id,
            }),
            {
                preserveScroll: true,
                onError,
            },
        );
    };

    const groupForm = (group: Group | null) => (
        <form
            onSubmit={(event) => submitGroup(group, event)}
            className="space-y-4 rounded-xl border border-slate-200 bg-white p-4"
        >
            <div>
                <label
                    htmlFor={`group-name-${group?.id ?? 'new'}`}
                    className="mb-1 block text-sm font-medium"
                >
                    ჯგუფის დასახელება
                </label>
                <Input
                    id={`group-name-${group?.id ?? 'new'}`}
                    name="name"
                    defaultValue={group?.name ?? ''}
                    placeholder="მაგ. ჯგუფი 1"
                    maxLength={64}
                    required
                />
            </div>
            <fieldset>
                <legend className="mb-2 text-sm font-medium">
                    ჯგუფის წევრები
                </legend>
                {students.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        კლასში აქტიური მოსწავლე არ არის.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                        {students.map((student) => (
                            <label
                                key={student.id}
                                className="flex min-h-11 items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    name="student_ids[]"
                                    value={student.id}
                                    defaultChecked={group?.studentIds.includes(
                                        student.id,
                                    )}
                                    className="h-4 w-4"
                                />
                                {student.name}
                            </label>
                        ))}
                    </div>
                )}
            </fieldset>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={sending}>
                    შენახვა
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setEditingGroup(null)}
                >
                    გაუქმება
                </Button>
            </div>
        </form>
    );

    return (
        <PortalLayout>
            <Head title={`კლასი ${schoolClass.name}`} />

            <Link
                href={AcademicStructureController.index.url()}
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:underline"
            >
                <ArrowLeft size={16} /> სასწავლო წლები და კლასები
            </Link>

            <h1 className="mb-1 text-2xl">კლასი {schoolClass.name}</h1>
            <p className="mb-6 text-sm text-slate-500">
                {schoolClass.academicYearName} · დამრიგებელი:{' '}
                {schoolClass.homeroomTeacherName ?? 'არ არის მინიჭებული'}
            </p>

            <section className="mb-8">
                <h2 className="mb-3 text-lg">კლასის მონაცემები</h2>
                <form
                    onSubmit={submitProfile}
                    className="space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label
                                htmlFor="grade_level"
                                className="mb-1 block text-sm font-medium"
                            >
                                საფეხური (კლასის ნომერი)
                            </label>
                            <Input
                                id="grade_level"
                                name="grade_level"
                                type="number"
                                min={0}
                                max={13}
                                defaultValue={schoolClass.gradeLevel ?? ''}
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="short_name"
                                className="mb-1 block text-sm font-medium"
                            >
                                მოკლე სახელი
                            </label>
                            <Input
                                id="short_name"
                                name="short_name"
                                maxLength={32}
                                defaultValue={schoolClass.shortName ?? ''}
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="home_room_id"
                                className="mb-1 block text-sm font-medium"
                            >
                                საკლასო ოთახი
                            </label>
                            <select
                                id="home_room_id"
                                name="home_room_id"
                                defaultValue={schoolClass.homeRoomId ?? ''}
                                className={selectClass}
                            >
                                <option value="">— არ არის —</option>
                                {rooms.map((room) => (
                                    <option key={room.id} value={room.id}>
                                        {room.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <fieldset>
                        <legend className="mb-2 text-sm font-medium">
                            მეორე დამრიგებელი
                        </legend>
                        {teachers.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                სკოლაში აქტიური მასწავლებელი ჯერ არ არის.
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                {teachers.map((teacher) => (
                                    <label
                                        key={teacher.id}
                                        className="flex min-h-11 items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="co_homeroom_ids[]"
                                            value={teacher.id}
                                            defaultChecked={schoolClass.coHomeroomIds.includes(
                                                teacher.id,
                                            )}
                                            className="h-4 w-4"
                                        />
                                        {teacher.name}
                                    </label>
                                ))}
                            </div>
                        )}
                        <p className="mt-1 text-xs text-slate-500">
                            ძირითადი დამრიგებელი იცვლება კლასების სიაში.
                        </p>
                    </fieldset>
                    <Button type="submit" disabled={sending}>
                        შენახვა
                    </Button>
                </form>
            </section>

            <section className="mb-8">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg">სასწავლო ჯგუფები</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditingGroup('new')}
                    >
                        <Plus size={16} /> ახალი ჯგუფი
                    </Button>
                </div>
                <p className="mb-3 text-sm text-slate-500">
                    ჯგუფი საჭიროა, როცა კლასი გაკვეთილზე ნაწილებად იყოფა (მაგ.
                    უცხო ენა). ერთი კლასის სხვადასხვა ჯგუფს ერთ დროს შეიძლება
                    ჰქონდეს გაკვეთილი.
                </p>

                {editingGroup === 'new' && (
                    <div className="mb-4">{groupForm(null)}</div>
                )}

                {groups.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <Users className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">ჯგუფი ჯერ არ არის.</p>
                    </div>
                ) : (
                    <ul className="space-y-3">
                        {groups.map((group) => (
                            <li key={group.id}>
                                {editingGroup === group.id ? (
                                    groupForm(group)
                                ) : (
                                    <div className="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="font-medium">
                                                {group.name}
                                            </p>
                                            <p className="text-sm text-slate-500">
                                                {group.studentIds.length}{' '}
                                                მოსწავლე
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditingGroup(group.id)
                                                }
                                            >
                                                რედაქტირება
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    deleteGroup(group)
                                                }
                                            >
                                                წაშლა
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section>
                <h2 className="mb-3 text-lg">ჩარიცხვების ისტორია</h2>
                {enrollments.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                        ჩანაწერი ჯერ არ არის. ისტორია იწერება მოსწავლის
                        დამატებისას, გადაყვანისას და გასვლისას.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                        <table className="w-full min-w-[480px] text-sm">
                            <thead className="bg-slate-50 text-left text-slate-600">
                                <tr>
                                    <th className="p-3 font-medium">
                                        მოსწავლე
                                    </th>
                                    <th className="p-3 font-medium">დან</th>
                                    <th className="p-3 font-medium">მდე</th>
                                    <th className="p-3 font-medium">სტატუსი</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {enrollments.map((enrollment) => (
                                    <tr key={enrollment.id}>
                                        <td className="p-3">
                                            {enrollment.studentName}
                                        </td>
                                        <td className="p-3">
                                            {enrollment.startsOn}
                                        </td>
                                        <td className="p-3">
                                            {enrollment.endsOn ?? '—'}
                                        </td>
                                        <td className="p-3">
                                            {ENROLLMENT_STATUS[
                                                enrollment.status
                                            ] ?? enrollment.status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </PortalLayout>
    );
}
