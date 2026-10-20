import { Head, router } from "@inertiajs/react";
import { useMemo, useState, type FormEvent } from "react";
import { Search, UserPlus, Users } from "lucide-react";
import { toast } from "sonner";
import PortalLayout from "@/layouts/portal/portal-layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatDay } from "@/lib/assessment";
import * as MemberController from "@/actions/App/Http/Controllers/Portal/MemberController";

type GuardianLinkRow = {
    id: number;
    studentId: number;
    studentName: string;
    isActive: boolean;
    canViewAcademic: boolean;
    canViewFinancial: boolean;
    canPickup: boolean;
    canReceiveNotifications: boolean;
};

type TeacherAssignmentRow = {
    id: number;
    className: string;
    subject: string | null;
};

type Member = {
    id: number;
    userName: string;
    userEmail: string;
    role: string;
    isActive: boolean;
    guardianLinks: GuardianLinkRow[];
    teacherAssignments: TeacherAssignmentRow[];
};

type PendingInvitation = {
    id: number;
    email: string;
    role: string;
    studentName: string | null;
    className: string | null;
    expiresAt: string;
    isExpired: boolean;
};

type Option = { id: number; name: string };

type StudentOption = Option & { hasAccount: boolean };

type EnrollmentRequest = {
    id: number;
    userName: string;
    userEmail: string;
    requestedRole: string;
    submittedName: string;
    submittedNationalId: string | null;
    matchedStudent: {
        id: number;
        name: string;
        className: string | null;
    } | null;
    createdAt: string | null;
};

type Props = {
    members: Member[];
    pendingInvitations: PendingInvitation[];
    students: StudentOption[];
    schoolClasses: Option[];
    enrollmentRequests: EnrollmentRequest[];
};

const ROLE_LABELS: Record<string, string> = {
    student: "მოსწავლე",
    guardian: "მშობელი",
    teacher: "მასწავლებელი",
    academic_manager: "აკადემიური მენეჯერი",
    accountant: "ბუღალტერი",
    editor: "რედაქტორი",
    librarian: "ბიბლიოთეკარი",
    admin: "ადმინისტრატორი",
    director: "დირექტორი",
};

const ROLE_OPTIONS = Object.entries(ROLE_LABELS);

const PERMISSIONS = [
    {
        name: "can_view_academic",
        key: "canViewAcademic",
        label: "აკადემიური ინფორმაცია",
    },
    {
        name: "can_view_financial",
        key: "canViewFinancial",
        label: "ფინანსური ინფორმაცია",
    },
    { name: "can_pickup", key: "canPickup", label: "აყვანის უფლება" },
    {
        name: "can_receive_notifications",
        key: "canReceiveNotifications",
        label: "შეტყობინებები",
    },
] as const;

const SELECT_CLASS =
    "h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm";

function PermissionCheckboxes({
    idPrefix,
    defaults,
}: {
    idPrefix: string;
    defaults: Record<string, boolean>;
}) {
    return (
        <fieldset className="grid grid-cols-1 gap-x-4 text-sm sm:grid-cols-2">
            <legend className="mb-1 text-sm font-medium">უფლებები</legend>
            {PERMISSIONS.map((permission) => (
                <label
                    key={permission.name}
                    htmlFor={`${idPrefix}-${permission.name}`}
                    className="flex min-h-11 items-center gap-2"
                >
                    <input
                        id={`${idPrefix}-${permission.name}`}
                        type="checkbox"
                        name={permission.name}
                        value="1"
                        defaultChecked={defaults[permission.name] ?? false}
                        className="h-4 w-4"
                    />
                    {permission.label}
                </label>
            ))}
        </fieldset>
    );
}

const post = (
    url: string,
    data: FormData | Record<string, unknown>,
    message: string,
    onDone?: () => void,
) =>
    router.post(url, data as Record<string, string>, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(message);
            onDone?.();
        },
        onError: (errors) =>
            toast.error(Object.values(errors)[0] ?? "შემოწმეთ ფორმის ველები."),
    });

function GuardianPanel({
    member,
    students,
}: {
    member: Member;
    students: StudentOption[];
}) {
    const linkedIds = new Set(
        member.guardianLinks.map((link) => link.studentId),
    );

    const saveLink = (
        link: GuardianLinkRow,
        target: HTMLFormElement,
        isActive: boolean,
    ) => {
        const form = new FormData(target);
        form.set("is_active", isActive ? "1" : "0");
        form.set("_method", "put");
        post(
            MemberController.updateGuardianLink.url(link.id),
            form,
            isActive ? "მშობლის უფლებები შენახულია." : "კავშირი გაუქმდა.",
        );
    };

    const addLink = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        post(
            MemberController.storeGuardianLink.url(member.id),
            new FormData(target),
            "მოსწავლე დაუკავშირდა მშობელს.",
            () => target.reset(),
        );
    };

    return (
        <div className="space-y-4">
            {member.guardianLinks.length === 0 && (
                <p className="text-sm text-slate-500">
                    მშობელს ჯერ არცერთი მოსწავლე არ აქვს მიბმული.
                </p>
            )}
            {member.guardianLinks.map((link) => (
                <form
                    key={link.id}
                    onSubmit={(event) => {
                        event.preventDefault();
                        saveLink(link, event.currentTarget, true);
                    }}
                    className="rounded-lg border border-slate-200 p-4"
                >
                    <p className="mb-2 font-medium">
                        {link.studentName}{" "}
                        {!link.isActive && (
                            <span className="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                გაუქმებული კავშირი
                            </span>
                        )}
                    </p>
                    <PermissionCheckboxes
                        idPrefix={`link-${link.id}`}
                        defaults={{
                            can_view_academic: link.canViewAcademic,
                            can_view_financial: link.canViewFinancial,
                            can_pickup: link.canPickup,
                            can_receive_notifications:
                                link.canReceiveNotifications,
                        }}
                    />
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button type="submit" size="sm" className="min-h-11">
                            {link.isActive ? "შენახვა" : "აღდგენა"}
                        </Button>
                        {link.isActive && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="min-h-11"
                                onClick={(event) => {
                                    if (
                                        !confirm(
                                            "გავაუქმოთ მშობლის კავშირი ამ მოსწავლესთან?",
                                        )
                                    ) {
                                        return;
                                    }

                                    const form = event.currentTarget.form;

                                    if (form) {
                                        saveLink(link, form, false);
                                    }
                                }}
                            >
                                კავშირის გაუქმება
                            </Button>
                        )}
                    </div>
                </form>
            ))}

            <form
                onSubmit={addLink}
                className="rounded-lg border border-dashed border-slate-300 p-4"
            >
                <label
                    htmlFor={`add-child-${member.id}`}
                    className="mb-1 block text-sm font-medium"
                >
                    სხვა მოსწავლის მიბმა
                </label>
                <select
                    id={`add-child-${member.id}`}
                    name="student_id"
                    required
                    className={SELECT_CLASS}
                >
                    <option value="">აირჩიეთ მოსწავლე</option>
                    {students
                        .filter((student) => !linkedIds.has(student.id))
                        .map((student) => (
                            <option key={student.id} value={student.id}>
                                {student.name}
                            </option>
                        ))}
                </select>
                <div className="mt-3">
                    <PermissionCheckboxes
                        idPrefix={`add-${member.id}`}
                        defaults={{
                            can_view_academic: true,
                            can_receive_notifications: true,
                        }}
                    />
                </div>
                <Button type="submit" size="sm" className="mt-3 min-h-11">
                    მიბმა
                </Button>
            </form>
        </div>
    );
}

function TeacherPanel({
    member,
    schoolClasses,
}: {
    member: Member;
    schoolClasses: Option[];
}) {
    const remove = (assignment: TeacherAssignmentRow) => {
        if (
            !confirm(
                `მოვხსნათ მინიჭება: ${assignment.className}${assignment.subject ? ` · ${assignment.subject}` : ""}?`,
            )
        ) {
            return;
        }

        router.delete(
            MemberController.destroyTeacherAssignment.url(assignment.id),
            {
                preserveScroll: true,
                onSuccess: () => toast.success("მინიჭება მოიხსნა."),
                onError: (errors) =>
                    toast.error(Object.values(errors)[0] ?? "ვერ მოიხსნა."),
            },
        );
    };

    const add = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        post(
            MemberController.storeTeacherAssignment.url(member.id),
            new FormData(target),
            "კლასი მიენიჭა.",
            () => target.reset(),
        );
    };

    return (
        <div className="space-y-4">
            {member.teacherAssignments.length === 0 ? (
                <p className="text-sm text-slate-500">
                    კლასი ჯერ არ არის მინიჭებული.
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    {member.teacherAssignments.map((assignment) => (
                        <li
                            key={assignment.id}
                            className="flex items-center justify-between gap-2 px-4 py-2 text-sm"
                        >
                            <span>
                                {assignment.className}
                                {assignment.subject &&
                                    ` · ${assignment.subject}`}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => remove(assignment)}
                            >
                                მოხსნა
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <form
                onSubmit={add}
                className="grid gap-3 rounded-lg border border-dashed border-slate-300 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
            >
                <div>
                    <label
                        htmlFor={`ta-class-${member.id}`}
                        className="mb-1 block text-sm font-medium"
                    >
                        კლასი
                    </label>
                    <select
                        id={`ta-class-${member.id}`}
                        name="school_class_id"
                        required
                        className={SELECT_CLASS}
                    >
                        <option value="">აირჩიეთ კლასი</option>
                        {schoolClasses.map((schoolClass) => (
                            <option key={schoolClass.id} value={schoolClass.id}>
                                {schoolClass.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label
                        htmlFor={`ta-subject-${member.id}`}
                        className="mb-1 block text-sm font-medium"
                    >
                        საგანი
                    </label>
                    <Input
                        id={`ta-subject-${member.id}`}
                        name="subject"
                        placeholder="მაგ. მათემატიკა"
                        className="h-11"
                    />
                </div>
                <Button type="submit" className="min-h-11">
                    მინიჭება
                </Button>
            </form>
        </div>
    );
}

export default function MembersIndex({
    members,
    pendingInvitations,
    students,
    schoolClasses,
    enrollmentRequests,
}: Props) {
    const [showInvite, setShowInvite] = useState(false);
    const [role, setRole] = useState("teacher");
    const [sending, setSending] = useState(false);
    const [manualStudent, setManualStudent] = useState<Record<number, string>>(
        {},
    );
    const [query, setQuery] = useState("");
    const [roleFilter, setRoleFilter] = useState("");
    const [openMember, setOpenMember] = useState<number | null>(null);

    const visibleMembers = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase("ka-GE");

        return members.filter((member) => {
            if (roleFilter !== "" && member.role !== roleFilter) {
                return false;
            }

            if (needle === "") {
                return true;
            }

            const haystack = [
                member.userName,
                member.userEmail,
                ...member.guardianLinks.map((link) => link.studentName),
                ...member.teacherAssignments.map(
                    (a) => `${a.className} ${a.subject ?? ""}`,
                ),
            ]
                .join(" ")
                .toLocaleLowerCase("ka-GE");

            return haystack.includes(needle);
        });
    }, [members, query, roleFilter]);

    const submitInvite = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setSending(true);
        router.post(MemberController.store.url(), form, {
            onSuccess: () => {
                toast.success("მოწვევა გაიგზავნა.");
                setShowInvite(false);
                (event.target as HTMLFormElement).reset();
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? "შემოწმეთ ფორმის ველები.",
                ),
            onFinish: () => setSending(false),
        });
    };

    const revoke = (membershipId: number) => {
        if (
            !confirm("დარწმუნებული ხართ, რომ გსურთ ამ წევრის წვდომის გაუქმება?")
        ) {
            return;
        }

        router.post(
            MemberController.revoke.url(membershipId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success("წვდომა გაუქმდა."),
            },
        );
    };

    const cancelInvitation = (invitationId: number) => {
        router.delete(MemberController.cancelInvitation.url(invitationId), {
            preserveScroll: true,
        });
    };

    const resendInvitation = (invitationId: number) => {
        post(
            MemberController.resendInvitation.url(invitationId),
            {},
            "მოწვევა ხელახლა გაიგზავნა.",
        );
    };

    const approveEnrollment = (requestId: number, matched: boolean) => {
        const studentId = manualStudent[requestId];

        if (!matched && !studentId) {
            toast.error("აირჩიეთ მოსწავლე ხელით.");

            return;
        }

        router.post(
            MemberController.decideEnrollment.url(requestId),
            { decision: "approve", student_id: studentId ?? "" },
            {
                preserveScroll: true,
                onSuccess: () => toast.success("მოთხოვნა დამტკიცდა."),
                onError: () => toast.error("ვერ დამტკიცდა — გადაამოწმეთ."),
            },
        );
    };

    const rejectEnrollment = (requestId: number) => {
        const reason = prompt("უარყოფის მიზეზი (არასავალდებულო):") ?? "";

        router.post(
            MemberController.decideEnrollment.url(requestId),
            { decision: "reject", reason },
            {
                preserveScroll: true,
                onSuccess: () => toast.success("მოთხოვნა უარყოფილია."),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="წევრები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">წევრები</h1>
                <Button
                    onClick={() => setShowInvite((v) => !v)}
                    aria-expanded={showInvite}
                >
                    <UserPlus size={16} /> ახალი წევრის მოწვევა
                </Button>
            </div>

            {showInvite && (
                <form
                    onSubmit={submitInvite}
                    className="mb-8 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                >
                    <div>
                        <label
                            htmlFor="invite-email"
                            className="mb-1 block text-sm font-medium"
                        >
                            ელფოსტა
                        </label>
                        <Input
                            id="invite-email"
                            name="email"
                            type="email"
                            required
                            className="h-11"
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="invite-role"
                            className="mb-1 block text-sm font-medium"
                        >
                            როლი
                        </label>
                        <select
                            id="invite-role"
                            name="role"
                            required
                            value={role}
                            onChange={(event) => setRole(event.target.value)}
                            className={SELECT_CLASS}
                        >
                            {ROLE_OPTIONS.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {role === "student" && (
                        <div>
                            <label
                                htmlFor="invite-student-account"
                                className="mb-1 block text-sm font-medium"
                            >
                                მოსწავლე სიიდან
                            </label>
                            <select
                                id="invite-student-account"
                                name="student_id"
                                className={SELECT_CLASS}
                            >
                                <option value="">
                                    — მიბმის გარეშე (მოსწავლე თავად დაადასტურებს
                                    ვინაობას) —
                                </option>
                                {students
                                    .filter((student) => !student.hasAccount)
                                    .map((student) => (
                                        <option
                                            key={student.id}
                                            value={student.id}
                                        >
                                            {student.name}
                                        </option>
                                    ))}
                            </select>
                            <p className="mt-1 text-xs text-slate-500">
                                სიაში ჩანს მხოლოდ მოსწავლეები, რომლებსაც
                                ანგარიში ჯერ არ აქვთ.
                            </p>
                        </div>
                    )}

                    {role === "guardian" && (
                        <>
                            <div>
                                <label
                                    htmlFor="invite-student"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    მოსწავლე
                                </label>
                                <select
                                    id="invite-student"
                                    name="student_id"
                                    required
                                    className={SELECT_CLASS}
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
                            <PermissionCheckboxes
                                idPrefix="invite"
                                defaults={{
                                    can_view_academic: true,
                                    can_receive_notifications: true,
                                }}
                            />
                        </>
                    )}

                    {role === "teacher" && (
                        <>
                            <div>
                                <label
                                    htmlFor="invite-class"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    კლასი
                                </label>
                                <select
                                    id="invite-class"
                                    name="school_class_id"
                                    required
                                    className={SELECT_CLASS}
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
                                <label
                                    htmlFor="invite-subject"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    საგანი
                                </label>
                                <Input
                                    id="invite-subject"
                                    name="subject"
                                    placeholder="მაგ. მათემატიკა"
                                    className="h-11"
                                />
                                <p className="mt-1 text-xs text-slate-500">
                                    დამატებითი კლასები და საგნები მოწვევის
                                    მიღების შემდეგ წევრის ბარათიდან ემატება.
                                </p>
                            </div>
                        </>
                    )}

                    <Button
                        type="submit"
                        disabled={sending}
                        className="min-h-11"
                    >
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
                                className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium break-all">
                                        {invitation.email}
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {ROLE_LABELS[invitation.role] ??
                                            invitation.role}
                                        {invitation.studentName &&
                                            ` · ${invitation.studentName}`}
                                        {invitation.className &&
                                            ` · ${invitation.className}`}
                                        {" · "}
                                        {invitation.isExpired ? (
                                            <span className="font-medium text-amber-700">
                                                ვადა ამოიწურა
                                            </span>
                                        ) : (
                                            `მოქმედებს ${formatDay(invitation.expiresAt.slice(0, 10))}-მდე`
                                        )}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11"
                                        onClick={() =>
                                            resendInvitation(invitation.id)
                                        }
                                    >
                                        ხელახლა გაგზავნა
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11"
                                        onClick={() =>
                                            cancelInvitation(invitation.id)
                                        }
                                    >
                                        გაუქმება
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {enrollmentRequests.length > 0 && (
                <div className="mb-8">
                    <h2 className="mb-3 text-lg">
                        ვინაობის დასადასტურებელი მოთხოვნები
                    </h2>
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {enrollmentRequests.map((request) => (
                            <li
                                key={request.id}
                                className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">
                                        {request.userName}{" "}
                                        <span className="text-sm text-slate-500">
                                            {request.userEmail}
                                        </span>
                                    </p>
                                    <p className="text-sm text-slate-500">
                                        {ROLE_LABELS[request.requestedRole] ??
                                            request.requestedRole}{" "}
                                        · აცხადებს: {request.submittedName}
                                        {request.submittedNationalId &&
                                            ` (${request.submittedNationalId})`}
                                    </p>
                                    {request.matchedStudent ? (
                                        <p className="mt-1 text-sm text-emerald-700">
                                            დამთხვევა:{" "}
                                            {request.matchedStudent.name}
                                            {request.matchedStudent.className &&
                                                ` · ${request.matchedStudent.className}`}
                                        </p>
                                    ) : (
                                        <p className="mt-1 text-sm text-amber-700">
                                            ავტომატური დამთხვევა ვერ მოხერხდა
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {!request.matchedStudent && (
                                        <select
                                            aria-label="მოსწავლე ხელით"
                                            className="h-11 rounded-md border border-slate-300 px-2 text-sm"
                                            value={
                                                manualStudent[request.id] ?? ""
                                            }
                                            onChange={(event) =>
                                                setManualStudent((prev) => ({
                                                    ...prev,
                                                    [request.id]:
                                                        event.target.value,
                                                }))
                                            }
                                        >
                                            <option value="">
                                                აირჩიეთ მოსწავლე
                                            </option>
                                            {students.map((student) => (
                                                <option
                                                    key={student.id}
                                                    value={student.id}
                                                >
                                                    {student.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    <Button
                                        size="sm"
                                        className="min-h-11"
                                        onClick={() =>
                                            approveEnrollment(
                                                request.id,
                                                request.matchedStudent !== null,
                                            )
                                        }
                                    >
                                        დამტკიცება
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11"
                                        onClick={() =>
                                            rejectEnrollment(request.id)
                                        }
                                    >
                                        უარყოფა
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="mb-3 grid gap-3 sm:grid-cols-[1fr_16rem]">
                <div className="relative">
                    <label htmlFor="member-search" className="sr-only">
                        ძებნა
                    </label>
                    <Search
                        size={16}
                        className="pointer-events-none absolute top-3.5 left-3 text-slate-400"
                        aria-hidden
                    />
                    <Input
                        id="member-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="სახელი, ელფოსტა, მოსწავლე ან კლასი"
                        className="h-11 pl-9"
                    />
                </div>
                <div>
                    <label htmlFor="member-role-filter" className="sr-only">
                        როლით ფილტრი
                    </label>
                    <select
                        id="member-role-filter"
                        value={roleFilter}
                        onChange={(event) => setRoleFilter(event.target.value)}
                        className={SELECT_CLASS}
                    >
                        <option value="">ყველა როლი</option>
                        {ROLE_OPTIONS.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {visibleMembers.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <Users className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">წევრი ვერ მოიძებნა.</p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {visibleMembers.map((member) => {
                        const manageable =
                            member.isActive &&
                            (member.role === "guardian" ||
                                member.role === "teacher");
                        const isOpen = openMember === member.id;
                        const summary =
                            member.role === "guardian"
                                ? member.guardianLinks
                                      .filter((link) => link.isActive)
                                      .map((link) => link.studentName)
                                : member.teacherAssignments.map(
                                      (a) =>
                                          `${a.className}${a.subject ? ` · ${a.subject}` : ""}`,
                                  );

                        return (
                            <li key={member.id} className="p-4">
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {member.userName}{" "}
                                            <span className="text-sm break-all text-slate-500">
                                                {member.userEmail}
                                            </span>
                                        </p>
                                        <p className="text-sm text-slate-500">
                                            {ROLE_LABELS[member.role] ??
                                                member.role}
                                            {summary.length > 0 &&
                                                ` · ${summary.join(", ")}`}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={`inline-flex w-fit rounded-full px-3 py-1 text-xs font-medium ${
                                                member.isActive
                                                    ? "bg-emerald-100 text-emerald-800"
                                                    : "bg-slate-100 text-slate-500"
                                            }`}
                                        >
                                            {member.isActive
                                                ? "აქტიური"
                                                : "გაუქმებული"}
                                        </span>
                                        {manageable && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="min-h-11"
                                                aria-expanded={isOpen}
                                                aria-controls={`member-panel-${member.id}`}
                                                onClick={() =>
                                                    setOpenMember(
                                                        isOpen
                                                            ? null
                                                            : member.id,
                                                    )
                                                }
                                            >
                                                {member.role === "guardian"
                                                    ? "მოსწავლეები და უფლებები"
                                                    : "კლასები და საგნები"}
                                            </Button>
                                        )}
                                        {member.isActive && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="min-h-11"
                                                onClick={() =>
                                                    revoke(member.id)
                                                }
                                            >
                                                გაუქმება
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {manageable && isOpen && (
                                    <div
                                        id={`member-panel-${member.id}`}
                                        className="mt-4"
                                    >
                                        {member.role === "guardian" ? (
                                            <GuardianPanel
                                                member={member}
                                                students={students}
                                            />
                                        ) : (
                                            <TeacherPanel
                                                member={member}
                                                schoolClasses={schoolClasses}
                                            />
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </PortalLayout>
    );
}
