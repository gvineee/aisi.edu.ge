import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FilePlus2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { selectClass } from '@/components/documents/labels';
import { firstError } from '@/lib/assessment';

type Props = {
    templates: {
        id: number;
        title: string;
        workspace: string;
        category: string | null;
        versionOrdinal: number | null;
        filename: string | null;
    }[];
    workspaces: { id: number; title: string; requiresApproval: boolean }[];
};

/**
 * Template library: start a new document from a template's published
 * version, in a workspace the user may add files to. The template stays
 * unchanged; the new document remembers which version it came from.
 */
export default function DocumentTemplates({ templates, workspaces }: Props) {
    const [openId, setOpenId] = useState<number | null>(null);
    const [sending, setSending] = useState(false);

    const use = (event: FormEvent<HTMLFormElement>, templateId: number) => {
        event.preventDefault();
        router.post(
            `/documents/templates/${templateId}/use`,
            Object.fromEntries(new FormData(event.currentTarget)),
            {
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ შეიქმნა.'),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="შაბლონები" />
            <Link
                href="/documents"
                className="mb-3 inline-flex min-h-11 items-center text-sm underline-offset-4 hover:underline"
            >
                ← ფაილების ცენტრი
            </Link>
            <h1 className="mb-1 text-2xl">შაბლონები</h1>
            <p className="mb-6 text-sm text-slate-600">
                შაბლონიდან იქმნება ახალი დოკუმენტი მისი მოქმედი ვერსიის ასლით.
                შაბლონს დოკუმენტის გვერდიდან ნიშნავს ის, ვინც მას მართავს.
            </p>

            {templates.length === 0 ? (
                <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
                    შაბლონი ჯერ არ არის.
                </p>
            ) : (
                <ul className="space-y-3">
                    {templates.map((t) => (
                        <li
                            key={t.id}
                            className="rounded-xl border border-slate-200 bg-white p-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <Link
                                        href={`/documents/${t.id}`}
                                        className="inline-flex min-h-11 items-center font-medium break-words underline-offset-4 hover:underline"
                                    >
                                        {t.title}
                                    </Link>
                                    <p className="text-sm text-slate-600">
                                        {[
                                            t.workspace,
                                            t.category,
                                            t.versionOrdinal &&
                                                `ვერსია ${t.versionOrdinal}`,
                                            t.filename,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                {workspaces.length > 0 && openId !== t.id && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setOpenId(t.id)}
                                    >
                                        <FilePlus2 aria-hidden /> გამოყენება
                                    </Button>
                                )}
                            </div>
                            {openId === t.id && (
                                <form
                                    onSubmit={(e) => use(e, t.id)}
                                    className="mt-3 grid gap-3 sm:grid-cols-2"
                                >
                                    <label className="grid gap-1 text-sm">
                                        ახალი დოკუმენტის სათაური
                                        <Input
                                            name="title"
                                            required
                                            maxLength={255}
                                            defaultValue={t.title}
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm">
                                        სივრცე
                                        <select
                                            name="workspace_id"
                                            required
                                            className={selectClass}
                                        >
                                            {workspaces.map((w) => (
                                                <option key={w.id} value={w.id}>
                                                    {w.title}
                                                    {w.requiresApproval
                                                        ? ' (დამტკიცებით)'
                                                        : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <div className="flex flex-wrap gap-2 sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={sending}
                                        >
                                            შექმნა
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setOpenId(null)}
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
        </PortalLayout>
    );
}
