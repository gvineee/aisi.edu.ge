import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Award, FileDown, Pencil, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';

type Template = {
    id: number;
    name: string;
    kind: string;
    title: string;
    body: string;
    signatory: string | null;
    orientation: 'portrait' | 'landscape';
    is_active: boolean;
};

type Issued = {
    id: number;
    number: string;
    kind: string;
    title: string;
    recipient: string | null;
    className: string | null;
    issuedOn: string;
    code: string;
    revokedAt: string | null;
    revokeReason: string | null;
};

type Props = {
    templates: Template[];
    classes: { id: number; name: string }[];
    students: { id: number; name: string; classId: number }[];
    issued: Issued[];
    search: string;
    placeholders: string[];
};

const KINDS: Record<string, string> = {
    certificate: 'ცნობა',
    diploma: 'დიპლომი / სიგელი',
    letter: 'წერილი',
};
const PLACEHOLDER_HELP: Record<string, string> = {
    name: 'სახელი, გვარი',
    class: 'კლასი',
    year: 'სასწავლო წელი',
    date: 'გაცემის თარიღი',
    school: 'სკოლა',
    number: 'ნომერი',
};
const field =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';
const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმა.');

/**
 * Templates, issuing and the numbered register of certificates, diplomas
 * and letters. A PDF is rendered from what was issued; a mistake is revoked
 * and issued again.
 */
export default function CertificatesIndex({
    templates,
    classes,
    students,
    issued,
    search,
    placeholders,
}: Props) {
    const active = templates.filter((t) => t.is_active);
    const [editing, setEditing] = useState<Template | 'new' | null>(
        templates.length === 0 ? 'new' : null,
    );
    const [mode, setMode] = useState<'student' | 'class' | 'recipient'>(
        'student',
    );
    const [classId, setClassId] = useState<number | ''>(classes[0]?.id ?? '');
    const [revoking, setRevoking] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const saveTemplate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        data.set('is_active', data.get('is_active') ? '1' : '0');
        const existing = editing !== 'new' && editing !== null ? editing : null;
        if (existing) data.append('_method', 'put');
        router.post(
            existing
                ? `/portal/certificates/templates/${existing.id}`
                : '/portal/certificates/templates',
            data,
            {
                preserveScroll: true,
                onSuccess: () => setEditing(null),
                onError,
            },
        );
    };

    const issue = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSaving(true);
        router.post(
            '/portal/certificates/issue',
            new FormData(event.currentTarget),
            {
                preserveScroll: true,
                onError,
                onFinish: () => setSaving(false),
            },
        );
    };

    const template = editing !== 'new' && editing !== null ? editing : null;

    return (
        <PortalLayout>
            <Head title="ცნობები და დიპლომები" />
            <h1 className="mb-2 text-2xl">ცნობები და დიპლომები</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                შაბლონიდან გაცემულ დოკუმენტს აქვს წლიური ნომერი და შემოწმების
                კოდი. ვინც ქაღალდს იღებს, საიტზე გადაამოწმებს, რომ დოკუმენტი
                ნამდვილია და ძალაშია.
            </p>

            <section
                aria-labelledby="issue"
                className="mb-8 rounded-xl border border-slate-200 bg-white p-4"
            >
                <h2 id="issue" className="mb-3 text-lg">
                    გაცემა
                </h2>
                {active.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        ჯერ შექმენით შაბლონი (ქვემოთ).
                    </p>
                ) : (
                    <form
                        onSubmit={issue}
                        className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end"
                    >
                        <div>
                            <label
                                htmlFor="certificate_template_id"
                                className="mb-1 block text-sm font-medium"
                            >
                                შაბლონი
                            </label>
                            <select
                                id="certificate_template_id"
                                name="certificate_template_id"
                                className={field}
                            >
                                {active.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label
                                htmlFor="mode"
                                className="mb-1 block text-sm font-medium"
                            >
                                ვის
                            </label>
                            <select
                                id="mode"
                                name="mode"
                                value={mode}
                                onChange={(e) =>
                                    setMode(e.target.value as typeof mode)
                                }
                                className={field}
                            >
                                <option value="student">ერთ მოსწავლეს</option>
                                <option value="class">მთელ კლასს</option>
                                <option value="recipient">
                                    სხვა ადრესატს (წერილი)
                                </option>
                            </select>
                        </div>
                        {mode === 'recipient' ? (
                            <div className="lg:col-span-1">
                                <label
                                    htmlFor="recipient"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    ადრესატი
                                </label>
                                <Input
                                    id="recipient"
                                    name="recipient"
                                    required
                                    maxLength={200}
                                />
                            </div>
                        ) : (
                            <div>
                                <label
                                    htmlFor="school_class_id"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    კლასი
                                </label>
                                <select
                                    id="school_class_id"
                                    name={
                                        mode === 'class'
                                            ? 'school_class_id'
                                            : undefined
                                    }
                                    value={classId}
                                    onChange={(e) =>
                                        setClassId(Number(e.target.value))
                                    }
                                    className={field}
                                >
                                    {classes.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {mode === 'student' && (
                            <div>
                                <label
                                    htmlFor="student_id"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    მოსწავლე
                                </label>
                                <select
                                    id="student_id"
                                    name="student_id"
                                    required
                                    className={field}
                                >
                                    {students
                                        .filter((s) => s.classId === classId)
                                        .map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                </select>
                            </div>
                        )}
                        <Button type="submit" disabled={saving}>
                            <Award className="mr-2 h-4 w-4" /> გაცემა
                        </Button>
                    </form>
                )}
            </section>

            <section aria-labelledby="register" className="mb-8">
                <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <h2 id="register" className="text-lg">
                        რეესტრი
                    </h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/portal/certificates',
                                { q: new FormData(e.currentTarget).get('q') },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <label htmlFor="q" className="sr-only">
                            ძებნა
                        </label>
                        <Input
                            id="q"
                            name="q"
                            defaultValue={search}
                            placeholder="სახელი, ნომერი (2026/0001) ან კოდი"
                            className="w-72"
                        />
                    </form>
                </div>
                {issued.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                        ჩანაწერი არ არის.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                        <table className="w-full min-w-[760px] text-sm">
                            <caption className="sr-only">
                                გაცემული დოკუმენტები
                            </caption>
                            <thead className="bg-slate-50 text-left text-xs text-slate-600">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-medium"
                                    >
                                        №
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-3 font-medium"
                                    >
                                        დოკუმენტი
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-3 font-medium"
                                    >
                                        ვის
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-3 font-medium"
                                    >
                                        თარიღი
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-3 font-medium"
                                    >
                                        კოდი
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-medium"
                                    >
                                        <span className="sr-only">
                                            მოქმედება
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {issued.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-slate-100 align-top"
                                    >
                                        <th
                                            scope="row"
                                            className="px-4 py-2.5 text-left font-medium"
                                        >
                                            {row.number}
                                        </th>
                                        <td className="px-3 py-2.5">
                                            {row.title}
                                            <span className="block text-xs text-slate-500">
                                                {KINDS[row.kind] ?? row.kind}
                                            </span>
                                            {row.revokedAt && (
                                                <span className="mt-1 block text-xs text-rose-700">
                                                    გაუქმებულია:{' '}
                                                    {row.revokeReason}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {row.recipient}
                                            {row.className && (
                                                <span className="block text-xs text-slate-500">
                                                    {row.className}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            {formatDay(row.issuedOn)}
                                        </td>
                                        <td className="px-3 py-2.5 font-mono text-xs">
                                            {row.code}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <a
                                                        href={`/portal/certificates/${row.id}/pdf`}
                                                        target="_blank"
                                                        rel="noopener"
                                                    >
                                                        <FileDown className="mr-1 h-4 w-4" />{' '}
                                                        PDF
                                                    </a>
                                                </Button>
                                                {!row.revokedAt &&
                                                    (revoking === row.id ? (
                                                        <form
                                                            className="flex gap-2"
                                                            onSubmit={(e) => {
                                                                e.preventDefault();
                                                                router.post(
                                                                    `/portal/certificates/${row.id}/revoke`,
                                                                    new FormData(
                                                                        e.currentTarget,
                                                                    ),
                                                                    {
                                                                        preserveScroll: true,
                                                                        onSuccess:
                                                                            () =>
                                                                                setRevoking(
                                                                                    null,
                                                                                ),
                                                                        onError,
                                                                    },
                                                                );
                                                            }}
                                                        >
                                                            <Input
                                                                name="reason"
                                                                required
                                                                maxLength={500}
                                                                placeholder="მიზეზი"
                                                                aria-label="გაუქმების მიზეზი"
                                                                className="h-9 w-40"
                                                            />
                                                            <Button
                                                                type="submit"
                                                                variant="destructive"
                                                                size="sm"
                                                            >
                                                                გაუქმება
                                                            </Button>
                                                        </form>
                                                    ) : (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setRevoking(
                                                                    row.id,
                                                                )
                                                            }
                                                        >
                                                            გაუქმება
                                                        </Button>
                                                    ))}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section aria-labelledby="templates">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="templates" className="text-lg">
                        შაბლონები
                    </h2>
                    {editing === null && (
                        <Button
                            variant="outline"
                            onClick={() => setEditing('new')}
                        >
                            <Plus className="mr-2 h-4 w-4" /> ახალი შაბლონი
                        </Button>
                    )}
                </div>
                {templates.length > 0 && (
                    <ul className="mb-4 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {templates.map((t) => (
                            <li
                                key={t.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                            >
                                <span>
                                    <span className="font-medium">
                                        {t.name}
                                    </span>{' '}
                                    <span className="text-slate-500">
                                        · {KINDS[t.kind] ?? t.kind}
                                        {!t.is_active && ' · გამორთული'}
                                    </span>
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setEditing(t)}
                                >
                                    <Pencil className="mr-1 h-4 w-4" />{' '}
                                    რედაქტირება
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                {editing !== null && (
                    <form
                        key={template?.id ?? 'new'}
                        onSubmit={saveTemplate}
                        className="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2"
                    >
                        <div>
                            <label
                                htmlFor="t-name"
                                className="mb-1 block text-sm font-medium"
                            >
                                შაბლონის სახელი
                            </label>
                            <Input
                                id="t-name"
                                name="name"
                                required
                                maxLength={160}
                                defaultValue={template?.name}
                                placeholder="მაგ. ცნობა სწავლის შესახებ"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="t-kind"
                                className="mb-1 block text-sm font-medium"
                            >
                                სახე
                            </label>
                            <select
                                id="t-kind"
                                name="kind"
                                defaultValue={template?.kind ?? 'certificate'}
                                className={field}
                            >
                                {Object.entries(KINDS).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="sm:col-span-2">
                            <label
                                htmlFor="t-title"
                                className="mb-1 block text-sm font-medium"
                            >
                                სათაური დოკუმენტზე
                            </label>
                            <Input
                                id="t-title"
                                name="title"
                                required
                                maxLength={200}
                                defaultValue={template?.title ?? 'ცნობა'}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <label
                                htmlFor="t-body"
                                className="mb-1 block text-sm font-medium"
                            >
                                ტექსტი
                            </label>
                            <textarea
                                id="t-body"
                                name="body"
                                required
                                rows={6}
                                maxLength={5000}
                                defaultValue={
                                    template?.body ??
                                    'ეძლევა {name}-ს მასზედ, რომ ის ნამდვილად სწავლობს {school}-ში, {class} კლასში ({year} სასწავლო წელი).'
                                }
                                className="w-full rounded-md border border-slate-300 p-3 text-sm"
                            />
                            <p className="mt-1 text-xs text-slate-500">
                                ჩასასმელი ველები:{' '}
                                {placeholders.map((p) => (
                                    <code key={p} className="mr-2">
                                        {`{${p}}`} — {PLACEHOLDER_HELP[p] ?? p}
                                    </code>
                                ))}
                            </p>
                        </div>
                        <div>
                            <label
                                htmlFor="t-signatory"
                                className="mb-1 block text-sm font-medium"
                            >
                                ხელმომწერი
                            </label>
                            <Input
                                id="t-signatory"
                                name="signatory"
                                maxLength={160}
                                defaultValue={template?.signatory ?? ''}
                                placeholder="მაგ. სკოლის დირექტორი"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="t-orientation"
                                className="mb-1 block text-sm font-medium"
                            >
                                გვერდი
                            </label>
                            <select
                                id="t-orientation"
                                name="orientation"
                                defaultValue={
                                    template?.orientation ?? 'portrait'
                                }
                                className={field}
                            >
                                <option value="portrait">ვერტიკალური</option>
                                <option value="landscape">
                                    ჰორიზონტალური (დიპლომი)
                                </option>
                            </select>
                        </div>
                        <label className="flex min-h-11 items-center gap-2 text-sm sm:col-span-2">
                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                defaultChecked={template?.is_active ?? true}
                            />{' '}
                            აქტიური
                        </label>
                        <div className="flex gap-2 sm:col-span-2">
                            <Button type="submit">შენახვა</Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setEditing(null)}
                            >
                                გაუქმება
                            </Button>
                        </div>
                    </form>
                )}
            </section>
        </PortalLayout>
    );
}
