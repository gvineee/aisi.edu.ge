import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { BookMarked, Plus } from 'lucide-react';
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

type ItemSummary = {
    id: number;
    title: string;
    subjectName: string | null;
    status: string;
    updatedAt: string | null;
};

type Props = {
    student: { id: number; name: string };
    items: ItemSummary[];
    canCreate: boolean;
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'მონახაზი',
    submitted: 'განსახილველად გაგზავნილია',
    published: 'გამოქვეყნებულია',
    returned: 'დაბრუნებულია შესასწორებლად',
    archived: 'დაარქივებულია',
};

export default function PortfolioIndex({ student, items, canCreate }: Props) {
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
                {canCreate && (
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus size={18} /> ნამუშევრის დამატება
                    </Button>
                )}
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
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

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
