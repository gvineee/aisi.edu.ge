import { Head, router } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import { IdCard } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Workload = {
    className: string;
    subjectName: string;
    weeklyHours: number | null;
};

type Staff = {
    id: number;
    fullName: string;
    shortName: string | null;
    isPlaceholder: boolean;
    status: 'active' | 'inactive';
    account: { id: number; name: string; email: string } | null;
    weeklyHours: number;
    lessonsPerWeek: number;
    workload: Workload[];
};

type Member = { id: number; name: string; email: string };

type Props = {
    years: { id: number; name: string }[];
    yearId: number | null;
    canManage: boolean;
    staff: Staff[];
    members: Member[];
};

const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

function StaffCard({
    member,
    members,
    canManage,
}: {
    member: Staff;
    members: Member[];
    canManage: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [userId, setUserId] = useState('');
    const [busy, setBusy] = useState(false);
    const done = {
        preserveScroll: true,
        onError,
        onFinish: () => setBusy(false),
    };

    const link = () => {
        if (userId === '') return;
        setBusy(true);
        router.post(
            `/portal/staff/${member.id}/link`,
            { user_id: Number(userId) },
            done,
        );
    };

    const unlink = () => {
        setBusy(true);
        router.delete(`/portal/staff/${member.id}/link`, done);
    };

    const toggleStatus = () => {
        setBusy(true);
        router.put(
            `/portal/staff/${member.id}`,
            {
                full_name: member.fullName,
                short_name: member.shortName,
                status: member.status === 'active' ? 'inactive' : 'active',
            },
            done,
        );
    };

    return (
        <li className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-medium">
                        {member.fullName}
                        {member.shortName && (
                            <span className="ml-2 text-sm text-slate-500">
                                ({member.shortName})
                            </span>
                        )}
                    </p>
                    <div className="mt-1 flex flex-wrap gap-2 text-xs">
                        {member.isPlaceholder && (
                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900">
                                ვაკანსია
                            </span>
                        )}
                        {member.status === 'inactive' && (
                            <span className="rounded-full bg-slate-200 px-2 py-0.5 text-slate-700">
                                აღარ მუშაობს
                            </span>
                        )}
                        {member.account ? (
                            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">
                                ანგარიში: {member.account.email}
                            </span>
                        ) : (
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">
                                ანგარიში არ აქვს
                            </span>
                        )}
                    </div>
                </div>
                <dl className="flex gap-4 text-right text-sm">
                    <div>
                        <dt className="text-xs text-slate-500">დატვირთვა</dt>
                        <dd className="font-semibold">
                            {member.weeklyHours} სთ/კვ
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">ცხრილში</dt>
                        <dd className="font-semibold">
                            {member.lessonsPerWeek} გაკვ/კვ
                        </dd>
                    </div>
                </dl>
            </div>

            <button
                type="button"
                className="mt-3 min-h-11 text-sm font-medium text-[var(--brand-primary,#132B45)] underline-offset-4 hover:underline"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
            >
                {open
                    ? 'დამალვა'
                    : `დატვირთვა კლასებით (${member.workload.length})`}
            </button>

            {open && (
                <div className="mt-2 space-y-4">
                    {member.workload.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            ამ წელს დატვირთვა არ აქვს.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[420px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-500">
                                        <th className="py-1 pr-3 font-medium">
                                            კლასი
                                        </th>
                                        <th className="py-1 pr-3 font-medium">
                                            საგანი
                                        </th>
                                        <th className="py-1 text-right font-medium">
                                            სთ/კვ
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {member.workload.map((row, index) => (
                                        <tr
                                            key={index}
                                            className="border-t border-slate-100"
                                        >
                                            <td className="py-1.5 pr-3">
                                                {row.className}
                                            </td>
                                            <td className="py-1.5 pr-3">
                                                {row.subjectName}
                                            </td>
                                            <td className="py-1.5 text-right">
                                                {row.weeklyHours ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {canManage && (
                        <div className="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3">
                            {member.account ? (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={unlink}
                                >
                                    ანგარიშის მოხსნა
                                </Button>
                            ) : (
                                <>
                                    <div className="min-w-[220px] flex-1">
                                        <label
                                            htmlFor={`link-${member.id}`}
                                            className="mb-1 block text-xs font-medium text-slate-600"
                                        >
                                            სკოლის ანგარიშის მიბმა
                                        </label>
                                        <select
                                            id={`link-${member.id}`}
                                            value={userId}
                                            onChange={(event) =>
                                                setUserId(event.target.value)
                                            }
                                            className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                                        >
                                            <option value="">
                                                აირჩიეთ წევრი
                                            </option>
                                            {members.map((user) => (
                                                <option
                                                    key={user.id}
                                                    value={user.id}
                                                >
                                                    {user.name} — {user.email}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <Button
                                        disabled={busy || userId === ''}
                                        onClick={link}
                                    >
                                        მიბმა
                                    </Button>
                                </>
                            )}
                            <Button
                                variant="ghost"
                                disabled={busy}
                                onClick={toggleStatus}
                            >
                                {member.status === 'active'
                                    ? 'აღარ მუშაობს'
                                    : 'ისევ მუშაობს'}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * The staff directory: everyone who teaches at the school, their weekly
 * workload by class and subject, and the login each one uses. Teachers
 * imported from EduPage appear here before they have an account; linking
 * an account puts their lessons into their own portal.
 */
export default function StaffIndex({
    years,
    yearId,
    canManage,
    staff,
    members,
}: Props) {
    const [query, setQuery] = useState('');
    const [adding, setAdding] = useState(false);

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return needle === ''
            ? staff
            : staff.filter((member) =>
                  member.fullName.toLowerCase().includes(needle),
              );
    }, [query, staff]);

    const totals = useMemo(
        () => ({
            people: staff.filter((member) => member.status === 'active').length,
            withoutAccount: staff.filter(
                (member) => member.account === null && !member.isPlaceholder,
            ).length,
            hours: staff.reduce((sum, member) => sum + member.weeklyHours, 0),
        }),
        [staff],
    );

    const add = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        setAdding(true);
        router.post('/portal/staff', new FormData(target), {
            preserveScroll: true,
            onSuccess: () => target.reset(),
            onError,
            onFinish: () => setAdding(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="პერსონალი და დატვირთვა" />
            <h1 className="mb-2 text-2xl">პერსონალი და დატვირთვა</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                სკოლის თანამშრომლები, მათი კვირის დატვირთვა კლასებისა და
                საგნების მიხედვით და პორტალის ანგარიში. ანგარიშის მიბმის შემდეგ
                მასწავლებელი საკუთარ გაკვეთილებს, დასწრებას და ჩანაცვლებებს
                პორტალში ხედავს.
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
                                router.get(
                                    '/portal/staff',
                                    { year: event.target.value },
                                    { preserveState: true },
                                )
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
                <div className="min-w-[220px] flex-1">
                    <label
                        htmlFor="search"
                        className="mb-1 block text-sm font-medium"
                    >
                        ძებნა
                    </label>
                    <Input
                        id="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="სახელი ან გვარი"
                    />
                </div>
            </div>

            <dl className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">
                        აქტიური თანამშრომელი
                    </dt>
                    <dd className="text-2xl font-semibold">{totals.people}</dd>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">ანგარიშის გარეშე</dt>
                    <dd className="text-2xl font-semibold">
                        {totals.withoutAccount}
                    </dd>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">
                        კვირის დატვირთვა სულ
                    </dt>
                    <dd className="text-2xl font-semibold">
                        {totals.hours} სთ
                    </dd>
                </div>
            </dl>

            {staff.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <IdCard className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">თანამშრომლები ჯერ არ არის.</p>
                    <p className="mt-1 text-sm text-slate-500">
                        დაამატეთ ქვემოთ ან გადმოიტანეთ EduPage-ის ცხრილიდან.
                    </p>
                </div>
            ) : (
                <ul className="space-y-3">
                    {visible.map((member) => (
                        <StaffCard
                            key={member.id}
                            member={member}
                            members={members}
                            canManage={canManage}
                        />
                    ))}
                </ul>
            )}

            {canManage && (
                <section className="mt-8" aria-labelledby="add-staff">
                    <h2 id="add-staff" className="mb-3 text-lg">
                        თანამშრომლის დამატება
                    </h2>
                    <form
                        onSubmit={add}
                        className="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-[1fr_200px_auto] sm:items-end"
                    >
                        <div>
                            <label
                                htmlFor="full_name"
                                className="mb-1 block text-sm font-medium"
                            >
                                სახელი და გვარი
                            </label>
                            <Input
                                id="full_name"
                                name="full_name"
                                required
                                maxLength={160}
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="short_name"
                                className="mb-1 block text-sm font-medium"
                            >
                                შემოკლება
                            </label>
                            <Input
                                id="short_name"
                                name="short_name"
                                maxLength={40}
                            />
                        </div>
                        <Button type="submit" disabled={adding}>
                            დამატება
                        </Button>
                    </form>
                </section>
            )}
        </PortalLayout>
    );
}
