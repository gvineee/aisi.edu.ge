import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Award, BookMarked, Plus, Printer } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Empty,
    EmptyHeader,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import { formatDay } from '@/lib/assessment';
import { postWithToast } from '@/lib/operations';

type ItemSummary = {
    id: number;
    title: string;
    subjectName: string | null;
    status: string;
    updatedAt: string | null;
    tags: string[];
};

type Props = {
    student: { id: number; name: string };
    items: ItemSummary[];
    canCreate: boolean;
    canTag: boolean;
    canAward: boolean;
    achievements: {
        id: number;
        title: string;
        description: string | null;
        awardedOn: string;
        shared: boolean;
    }[];
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'მონახაზი',
    submitted: 'განსახილველად გაგზავნილია',
    published: 'გამოქვეყნებულია',
    returned: 'დაბრუნებულია შესასწორებლად',
    archived: 'დაარქივებულია',
};

export default function PortfolioIndex({
    student,
    items,
    canCreate,
    canTag,
    canAward,
    achievements,
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [saving, setSaving] = useState(false);

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);

        const data = new FormData();
        data.append('title', title);
        if (description) data.append('description', description);
        if (file) data.append('file', file);

        router.post(`/portal/students/${student.id}/portfolio`, data, {
            forceFormData: true,
            onSuccess: () => {
                setCreateOpen(false);
                setTitle('');
                setDescription('');
                setFile(null);
                toast.success('ნამუშევარი დაემატა.');
            },
            onError: () => toast.error('ვერ შეინახა — გადაამოწმეთ ველები.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="პორტფოლიო" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl">{student.name}-ის პორტფოლიო</h1>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link
                        href={`/portal/students/${student.id}/portfolio/export`}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                    >
                        <Printer size={16} aria-hidden /> ექსპორტი / ბეჭდვა
                    </Link>
                    {canCreate && (
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus size={18} /> ნამუშევრის დამატება
                        </Button>
                    )}
                </div>
            </div>

            {items.length === 0 ? (
                <Empty className="rounded-xl border border-slate-200 bg-white p-8">
                    <EmptyHeader>
                        <BookMarked />
                        <EmptyTitle>ჯერ არცერთი ნამუშევარი არ არის</EmptyTitle>
                        <EmptyDescription>
                            {canCreate
                                ? 'დაამატეთ პირველი ნამუშევარი „ნამუშევრის დამატება" ღილაკით.'
                                : 'გამოქვეყნებული ნამუშევრები აქ გამოჩნდება.'}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2">
                    {items.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={`/portal/portfolio/${item.id}`}
                                className="block rounded-xl border border-slate-200 bg-white p-5 hover:border-slate-300"
                            >
                                {item.subjectName && (
                                    <p className="mb-1 text-xs font-semibold text-slate-500">
                                        {item.subjectName}
                                    </p>
                                )}
                                <h3 className="mb-2 font-medium">
                                    {item.title}
                                </h3>
                                <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                                    {STATUS_LABELS[item.status] ?? item.status}
                                </span>
                                {item.tags.length > 0 && (
                                    <span className="mt-2 flex flex-wrap gap-1">
                                        {item.tags.map((tag) => (
                                            <span
                                                key={tag}
                                                className="rounded-full bg-[var(--brand-secondary)] px-2 py-0.5 text-xs"
                                            >
                                                #{tag}
                                            </span>
                                        ))}
                                    </span>
                                )}
                            </Link>
                            {canTag && (
                                <form
                                    className="mt-2 flex gap-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        const raw = String(
                                            new FormData(
                                                event.currentTarget,
                                            ).get('tags') ?? '',
                                        );
                                        postWithToast(
                                            `/portal/portfolio/${item.id}/tags`,
                                            {
                                                tags: raw
                                                    .split(',')
                                                    .map((t) => t.trim())
                                                    .filter(Boolean),
                                            },
                                            'ტეგები შენახულია.',
                                        );
                                    }}
                                >
                                    <input
                                        aria-label={`ტეგები: ${item.title}`}
                                        name="tags"
                                        defaultValue={item.tags.join(', ')}
                                        placeholder="ტეგები, მძიმით"
                                        className="h-11 min-w-0 flex-1 rounded-md border border-slate-300 px-3 text-sm"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                    >
                                        ტეგები
                                    </Button>
                                    {item.status === 'published' && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                postWithToast(
                                                    `/portal/portfolio/${item.id}/archive`,
                                                    {},
                                                    'დაარქივდა.',
                                                )
                                            }
                                        >
                                            არქივი
                                        </Button>
                                    )}
                                </form>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <section className="mt-8" aria-labelledby="achievements-heading">
                <h2
                    id="achievements-heading"
                    className="mb-3 flex items-center gap-2 text-lg"
                >
                    <Award size={20} aria-hidden /> მიღწევები
                </h2>
                {achievements.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        მიღწევა ჯერ არ არის.
                    </p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {achievements.map((a) => (
                            <li
                                key={a.id}
                                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                            >
                                <p className="font-medium">{a.title}</p>
                                {a.description && (
                                    <p className="text-sm text-slate-600">
                                        {a.description}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-slate-500">
                                    {formatDay(a.awardedOn)}
                                    {a.shared ? '' : ' · ოჯახს არ უჩანს'}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
                {canAward && (
                    <form
                        className="mt-4 grid gap-2 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-[1fr_10rem_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            postWithToast(
                                `/portal/students/${student.id}/achievements`,
                                new FormData(form),
                                'მიღწევა დაემატა.',
                                () => form.reset(),
                            );
                        }}
                    >
                        <input
                            aria-label="მიღწევა"
                            name="title"
                            required
                            maxLength={255}
                            placeholder="მიღწევა"
                            className="h-11 rounded-md border border-slate-300 px-3 text-sm"
                        />
                        <input
                            aria-label="თარიღი"
                            name="awarded_on"
                            type="date"
                            required
                            className="h-11 rounded-md border border-slate-300 px-3 text-sm"
                        />
                        <Button type="submit">მინიჭება</Button>
                        <label className="flex min-h-11 items-center gap-2 text-sm sm:col-span-3">
                            <input
                                type="hidden"
                                name="shared_with_family"
                                value="0"
                            />
                            <input
                                type="checkbox"
                                name="shared_with_family"
                                value="1"
                                defaultChecked
                                className="h-4 w-4"
                            />
                            ოჯახს გაუზიარდეს
                        </label>
                    </form>
                )}
            </section>

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <DialogTitle>ახალი ნამუშევარი</DialogTitle>
                    <DialogDescription>
                        დაამატეთ სათაური, მოკლე აღწერა და, სურვილისამებრ, ფაილი
                        (PDF ან სურათი).
                    </DialogDescription>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="portfolio-title">სათაური</Label>
                            <input
                                id="portfolio-title"
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                required
                                maxLength={255}
                                className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="portfolio-description">
                                აღწერა
                            </Label>
                            <textarea
                                id="portfolio-description"
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                                className="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="portfolio-file">
                                ფაილი (არასავალდებულო)
                            </Label>
                            <input
                                id="portfolio-file"
                                type="file"
                                accept="application/pdf,image/png,image/jpeg,image/webp"
                                onChange={(event) =>
                                    setFile(event.target.files?.[0] ?? null)
                                }
                                className="w-full text-sm"
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={saving}
                            className="w-full"
                        >
                            შენახვა
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </PortalLayout>
    );
}
