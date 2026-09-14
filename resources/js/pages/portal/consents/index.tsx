import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FileCheck2, Plus } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Empty,
    EmptyHeader,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';

type ConsentFormSummary = {
    id: number;
    title: string;
    requiresSignature: boolean;
    createdAt: string | null;
    totalStudents: number;
    granted: number;
    denied: number;
    pending: number;
};

type Props = {
    forms: ConsentFormSummary[];
};

/**
 * Admin/director view of ConsentController::index — every count here is a
 * real query against consent_responses/students for this tenant, never a
 * placeholder number (CLAUDE.md's "don't fabricate an empty dashboard"
 * rule).
 */
export default function ConsentsIndex({ forms }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [requiresSignature, setRequiresSignature] = useState(true);
    const [saving, setSaving] = useState(false);

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);

        router.post(
            '/portal/consents',
            {
                title,
                body,
                requires_signature: requiresSignature,
            },
            {
                onSuccess: () => {
                    setCreateOpen(false);
                    setTitle('');
                    setBody('');
                    setRequiresSignature(true);
                    toast.success('თანხმობის ფორმა გამოქვეყნდა.');
                },
                onError: () =>
                    toast.error('ვერ გამოქვეყნდა — გადაამოწმეთ ველები.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="თანხმობები" />

            <div className="mb-6 flex items-center justify-between">
                <h1 className="text-2xl">თანხმობის ფორმები</h1>
                <Button onClick={() => setCreateOpen(true)}>
                    <Plus size={18} /> ახალი ფორმა
                </Button>
            </div>

            {forms.length === 0 ? (
                <Empty className="rounded-xl border border-slate-200 bg-white p-8">
                    <EmptyHeader>
                        <FileCheck2 />
                        <EmptyTitle>ჯერ არცერთი ფორმა არ არის</EmptyTitle>
                        <EmptyDescription>
                            გამოაქვეყნეთ პირველი თანხმობის ფორმა „ახალი ფორმა"
                            ღილაკით.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {forms.map((form) => (
                        <li
                            key={form.id}
                            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <p className="font-medium">{form.title}</p>
                                <p className="text-sm text-slate-500">
                                    დათანხმებულია {form.granted} · უარყოფილია{' '}
                                    {form.denied} · მოლოდინში {form.pending}{' '}
                                    (სულ {form.totalStudents} მოსწავლე)
                                </p>
                            </div>
                            <Link
                                href={`/portal/consents/${form.id}/roster`}
                                className="text-sm font-medium text-[var(--brand-primary)] hover:underline"
                            >
                                სრული ჩამონათვალი
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <DialogTitle>ახალი თანხმობის ფორმა</DialogTitle>
                    <DialogDescription>
                        ფორმა გამოქვეყნებისთანავე ხელმისაწვდომი იქნება ყველა
                        მშობლისთვის. ტექსტის შეცვლა შესაძლებელია მხოლოდ ახალი
                        ფორმის შექმნით.
                    </DialogDescription>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="consent-title">სათაური</Label>
                            <input
                                id="consent-title"
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
                            <Label htmlFor="consent-body">ტექსტი</Label>
                            <textarea
                                id="consent-body"
                                value={body}
                                onChange={(event) =>
                                    setBody(event.target.value)
                                }
                                required
                                className="min-h-32 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="consent-requires-signature"
                                checked={requiresSignature}
                                onCheckedChange={(checked) =>
                                    setRequiresSignature(checked === true)
                                }
                            />
                            <Label htmlFor="consent-requires-signature">
                                მშობლის ხელმოწერა სავალდებულოა
                            </Label>
                        </div>
                        <Button
                            type="submit"
                            disabled={saving}
                            className="w-full"
                        >
                            გამოქვეყნება
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </PortalLayout>
    );
}
