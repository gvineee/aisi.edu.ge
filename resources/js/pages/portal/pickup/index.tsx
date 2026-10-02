import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { KeyRound, Plus, Trash2, UserCheck } from 'lucide-react';
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

type Pickup = {
    id: number;
    fullName: string;
    relationship: string;
    idDocumentNumber: string | null;
};

type Child = {
    id: number;
    name: string;
    pickups: Pickup[];
    activeCodes: {
        id: number;
        personName: string | null;
        expiresAt: string;
    }[];
};

type IssuedCode = {
    code: string;
    studentName: string;
    personName: string;
    expiresAt: string;
};

type Props = {
    children: Child[];
    issuedCode: IssuedCode | null;
};

function issueCode(studentId: number, authorizedPickupId: number | null) {
    router.post(
        '/portal/pickup/codes',
        { student_id: studentId, authorized_pickup_id: authorizedPickupId },
        {
            preserveScroll: true,
            onError: () => toast.error('კოდი ვერ შეიქმნა.'),
        },
    );
}

/**
 * Every child/pickup row here comes from the authenticated guardian's own
 * active guardian_links (PickupController) — never a client-supplied
 * student id, and never demo data.
 */
export default function PickupIndex({ children, issuedCode }: Props) {
    const [addOpenFor, setAddOpenFor] = useState<number | null>(null);
    const [fullName, setFullName] = useState('');
    const [relationship, setRelationship] = useState('');
    const [idDocumentNumber, setIdDocumentNumber] = useState('');
    const [saving, setSaving] = useState(false);

    const closeDialog = () => {
        setAddOpenFor(null);
        setFullName('');
        setRelationship('');
        setIdDocumentNumber('');
    };

    const submitAdd = (event: FormEvent, studentId: number) => {
        event.preventDefault();
        setSaving(true);

        router.post(
            '/portal/pickup',
            {
                student_id: studentId,
                full_name: fullName,
                relationship,
                id_document_number: idDocumentNumber || undefined,
            },
            {
                onSuccess: () => {
                    closeDialog();
                    toast.success('უფლებამოსილი პირი დაემატა.');
                },
                onError: () => toast.error('ვერ შეინახა — გადაამოწმეთ ველები.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const remove = (pickupId: number) => {
        router.post(
            `/portal/pickup/${pickupId}/remove`,
            {},
            {
                onSuccess: () => toast.success('უფლებამოსილება გაუქმდა.'),
                onError: () => toast.error('ვერ გაუქმდა.'),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="უფლებამოსილი პირები" />

            <h1 className="mb-2 text-2xl">უფლებამოსილი პირები</h1>
            <p className="mb-6 text-sm text-slate-500">
                მართეთ, ვის აქვს უფლება, გამოიყვანოს თქვენი შვილი სკოლიდან,
                თქვენ გარდა.
            </p>

            {issuedCode && (
                <div
                    role="status"
                    className="mb-6 rounded-xl border-2 border-[var(--brand-accent)] bg-white p-5"
                >
                    <p className="text-sm text-slate-600">
                        ერთჯერადი გაყვანის კოდი — {issuedCode.studentName} ·{' '}
                        {issuedCode.personName}
                    </p>
                    <p className="my-2 font-mono text-3xl font-bold tracking-[0.3em]">
                        {issuedCode.code}
                    </p>
                    <p className="text-sm text-slate-600">
                        მოქმედებს{' '}
                        {new Date(issuedCode.expiresAt).toLocaleTimeString(
                            'ka-GE',
                            {
                                hour: '2-digit',
                                minute: '2-digit',
                                timeZone: 'Asia/Tbilisi',
                            },
                        )}
                        -მდე, მხოლოდ ერთხელ. გადაეცით ამ პირს — კოდი მეორედ აღარ
                        გამოჩნდება.
                    </p>
                </div>
            )}

            {children.length === 0 ? (
                <Empty className="rounded-xl border border-slate-200 bg-white p-8">
                    <EmptyHeader>
                        <UserCheck />
                        <EmptyTitle>ბავშვი დაკავშირებული არ არის</EmptyTitle>
                        <EmptyDescription>
                            თქვენს ანგარიშზე ჯერ არცერთი ბავშვი არ არის
                            დაკავშირებული.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="space-y-6">
                    {children.map((child) => (
                        <section
                            key={child.id}
                            className="rounded-xl border border-slate-200 bg-white p-5"
                        >
                            <div className="mb-4 flex items-center justify-between">
                                <h2 className="font-semibold">{child.name}</h2>
                                <Button
                                    variant="outline"
                                    onClick={() => setAddOpenFor(child.id)}
                                >
                                    <Plus size={16} /> პირის დამატება
                                </Button>
                            </div>

                            {child.pickups.length === 0 ? (
                                <p className="py-4 text-center text-sm text-slate-500">
                                    ჯერ არცერთი დამატებითი უფლებამოსილი პირი არ
                                    არის.
                                </p>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {child.pickups.map((pickup) => (
                                        <li
                                            key={pickup.id}
                                            className="flex items-center justify-between gap-3 py-3"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {pickup.fullName}
                                                </p>
                                                <p className="text-sm text-slate-500">
                                                    {pickup.relationship}
                                                    {pickup.idDocumentNumber &&
                                                        ` · პირადობის №${pickup.idDocumentNumber}`}
                                                </p>
                                            </div>
                                            <span className="flex shrink-0 gap-1">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        issueCode(
                                                            child.id,
                                                            pickup.id,
                                                        )
                                                    }
                                                >
                                                    <KeyRound size={16} /> კოდი
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`${pickup.fullName}-ის უფლებამოსილების გაუქმება`}
                                                    onClick={() =>
                                                        remove(pickup.id)
                                                    }
                                                >
                                                    <Trash2
                                                        size={18}
                                                        className="text-slate-500"
                                                    />
                                                </Button>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="mt-4 border-t border-slate-100 pt-4">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => issueCode(child.id, null)}
                                >
                                    <KeyRound size={16} /> კოდი ჩემთვის
                                </Button>
                                {child.activeCodes.length > 0 && (
                                    <ul className="mt-3 space-y-2 text-sm">
                                        {child.activeCodes.map((code) => (
                                            <li
                                                key={code.id}
                                                className="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2"
                                            >
                                                <span>
                                                    აქტიური კოდი —{' '}
                                                    {code.personName ??
                                                        'თქვენთვის'}{' '}
                                                    ·{' '}
                                                    {new Date(
                                                        code.expiresAt,
                                                    ).toLocaleTimeString(
                                                        'ka-GE',
                                                        {
                                                            hour: '2-digit',
                                                            minute: '2-digit',
                                                            timeZone:
                                                                'Asia/Tbilisi',
                                                        },
                                                    )}
                                                    -მდე
                                                </span>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/portal/pickup/codes/${code.id}/cancel`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess: () =>
                                                                    toast.success(
                                                                        'კოდი გაუქმდა.',
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                >
                                                    გაუქმება
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <Dialog
                open={addOpenFor !== null}
                onOpenChange={(open) => {
                    if (!open) closeDialog();
                }}
            >
                <DialogContent>
                    <DialogTitle>უფლებამოსილი პირის დამატება</DialogTitle>
                    <DialogDescription>
                        მიუთითეთ სახელი, გვარი და კავშირი ბავშვთან.
                    </DialogDescription>
                    <form
                        onSubmit={(event) =>
                            addOpenFor !== null && submitAdd(event, addOpenFor)
                        }
                        className="space-y-4"
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="pickup-full-name">
                                სახელი, გვარი
                            </Label>
                            <input
                                id="pickup-full-name"
                                value={fullName}
                                onChange={(event) =>
                                    setFullName(event.target.value)
                                }
                                required
                                maxLength={255}
                                className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="pickup-relationship">
                                კავშირი ბავშვთან
                            </Label>
                            <input
                                id="pickup-relationship"
                                value={relationship}
                                onChange={(event) =>
                                    setRelationship(event.target.value)
                                }
                                required
                                maxLength={100}
                                placeholder="მაგ. ბებია, ბიძა, ძიძა"
                                className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="pickup-id-document">
                                პირადობის №{' '}
                                <span className="font-normal text-slate-400">
                                    (არასავალდებულო)
                                </span>
                            </Label>
                            <input
                                id="pickup-id-document"
                                value={idDocumentNumber}
                                onChange={(event) =>
                                    setIdDocumentNumber(event.target.value)
                                }
                                maxLength={100}
                                className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
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
