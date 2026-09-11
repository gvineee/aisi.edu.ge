import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { UserPlus, Users } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import * as MemberController from '@/actions/App/Http/Controllers/Portal/MemberController';

type Member = {
    id: number;
    userName: string;
    userEmail: string;
    role: string;
    isActive: boolean;
    linkedStudents: string[];
    linkedClasses: string[];
};

type PendingInvitation = {
    id: number;
    email: string;
    role: string;
    studentName: string | null;
    className: string | null;
    expiresAt: string;
};

type Option = { id: number; name: string };

type Props = {
    members: Member[];
    pendingInvitations: PendingInvitation[];
    students: Option[];
    schoolClasses: Option[];
};

const ROLE_LABELS: Record<string, string> = {
    student: 'მოსწავლე',
    guardian: 'მშობელი',
    teacher: 'მასწავლებელი',
    academic_manager: 'აკადემიური მენეჯერი',
    accountant: 'ბუღალტერი',
    editor: 'რედაქტორი',
    admin: 'ადმინისტრატორი',
    director: 'დირექტორი',
};

const ROLE_OPTIONS = Object.entries(ROLE_LABELS);

export default function MembersIndex({
    members,
    pendingInvitations,
    students,
    schoolClasses,
}: Props) {
    const [showInvite, setShowInvite] = useState(false);
    const [role, setRole] = useState('teacher');
    const [sending, setSending] = useState(false);

    const submitInvite = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(MemberController.store.url(), form, {
            onSuccess: () => {
                toast.success('მოწვევა გაიგზავნა.');
                setShowInvite(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: () => toast.error('შემოწმეთ ფორმის ველები.'),
            onFinish: () => setSending(false),
        });
    };

    const revoke = (membershipId: number) => {
        if (!confirm('დარწმუნებული ხართ, რომ გსურთ ამ წევრის წვდომის გაუქმება?')) {
            return;
        }

        router.post(
            MemberController.revoke.url(membershipId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('წვდომა გაუქმდა.'),
            },
        );
    };

    const cancelInvitation = (invitationId: number) => {
        router.delete(MemberController.cancelInvitation.url(invitationId), {
            preserveScroll: true,
        });
    };

    return (
        <PortalLayout>
            <Head title="წევრები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">წევრები</h1>
                <Button onClick={() => setShowInvite((v) => !v)}>
                    <UserPlus size={16} /> ახალი წევრის მოწვევა
                </Button>
            </div>

            {showInvite && (
                <form
                    onSubmit={submitInvite}
                    className="mb-8 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            ელფოსტა
                        </label>
                        <Input name="email" type="email" required />
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium">
                            როლი
                        </label>
                        <select
                            name="role"
                            required
                            value={role}
                            onChange={(event) => setRole(event.target.value)}
                            className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                        >
                            {ROLE_OPTIONS.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {role === 'guardian' && (
                        <>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    მოსწავლე
                                </label>
                                <select
                                    name="student_id"
                                    required
                                    className="h-9 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">აირჩიეთ მოსწავლე</option>
                                    {students.map((student) => (
                                        <option
                                            key={student.id}
                                            value={student.id}
                                        >
                                            {student.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <fieldset className="grid grid-cols-2 gap-2 text-sm">
                                <legend className="mb-1 text-sm font-medium">
                                    უფლებები
                                </legend>
                                <label className="flex min-h-11 items-center gap-2">
                                    <input
                                        type="checkbox"
                                        name="can_view_academic"
                                        value="1"
                                        defaultChecked
                                        className="h-4 w-4"
                                    />
                                    აკადემიური ინფორმაცია
                                </label>
                                <label className="flex min-h-11 items-center gap-2">
                                    <input
                                        type="checkbox"
                                        name="can_view_financial"
                                        value="1"
                                        className="h-4 w-4"
                                    />
                                    ფინანსური ინფორმაცია
                                </label>
                                <label className="flex min-h-11 items-center gap-2">
                                    <input
                                        type="checkbox"
                                        name="can_pickup"
                                        value="1"
                                        className="h-4 w-4"
                                    />
                                    აყვანის უფლება
                                </label>
                                <label className="flex min-h-11 items-center gap-2">
                                    <input
                                        type="checkbox"
                                        name="can_receive_notifications"
                                        value="1"
                                        defaultChecked
                                        className="h-4 w-4"
                                    />
                                    შეტყობინებები
                                </label>
                            </fieldset>
                        </>
                    )}

                    {role === 'teacher' && (
                        <>
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
                                            {schoolClass.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">
                                    საგანი
                                </label>
                                <Input name="subject" placeholder="მაგ. მათემატიკა" />
                            </div>
                        </>
                    )}

                    <Button type="submit" disabled={sending}>
                        მოწვევის გაგზავნა
                    </Button>
                </form>
            )}

            {pendingInvitations.length > 0 && (
                <div className="mb-8">
                    <h2 className="mb-3 text-lg">გაგზავნილი მოწვევები</h2>
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {pendingInvitations.map((invitation) => (
                            <li
                                key={invitation.id}
                                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {invitation.email}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {ROLE_LABELS[invitation.role] ??
                                            invitation.role}
                                        {invitation.studentName &&
                                            ` · ${invitation.studentName}`}
                                        {invitation.className &&
                                            ` · ${invitation.className}`}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        cancelInvitation(invitation.id)
                                    }
                                >
                                    გაუქმება
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {members.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <Users className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">წევრი ვერ მოიძებნა.</p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <p className="font-medium">
                                    {member.userName}{' '}
                                    <span className="text-sm text-slate-500">
                                        {member.userEmail}
                                    </span>
                                </p>
                                <p className="text-sm text-slate-500">
                                    {ROLE_LABELS[member.role] ?? member.role}
                                    {member.linkedStudents.length > 0 &&
                                        ` · ${member.linkedStudents.join(', ')}`}
                                    {member.linkedClasses.length > 0 &&
                                        ` · ${member.linkedClasses.join(', ')}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-3">
                                <span
                                    className={`inline-flex w-fit rounded-full px-3 py-1 text-xs font-medium ${
                                        member.isActive
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {member.isActive ? 'აქტიური' : 'გაუქმებული'}
                                </span>
                                {member.isActive && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => revoke(member.id)}
                                    >
                                        გაუქმება
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
