import { Head, router } from '@inertiajs/react';
import { FileCheck2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Empty,
    EmptyHeader,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import { formatDateTime } from '@/lib/assessment';

type ConsentFormStatus = {
    id: number;
    title: string;
    body: string;
    requiresSignature: boolean;
    granted: boolean | null;
    respondedAt: string | null;
    closesAt: string | null;
    isClosed: boolean;
    history: {
        decision: 'granted' | 'declined' | 'revoked';
        guardianName: string | null;
        createdAt: string | null;
    }[];
};

const DECISIONS: Record<string, string> = {
    granted: 'თანხმობა',
    declined: 'უარი',
    revoked: 'თანხმობის გაუქმება',
};

type Child = {
    id: number;
    name: string;
    forms: ConsentFormStatus[];
};

type Props = {
    children: Child[];
};

/**
 * Every child/form row here comes from the authenticated guardian's own
 * active guardian_links (ConsentController::renderGuardianIndex) — never a
 * client-supplied student id.
 */
export default function ConsentsGuardianIndex({ children }: Props) {
    const respond = (formId: number, studentId: number, granted: boolean) => {
        router.post(
            `/portal/consents/${formId}/respond`,
            { student_id: studentId, granted },
            {
                onSuccess: () =>
                    toast.success(
                        granted ? 'თანხმობა დაფიქსირდა.' : 'უარი დაფიქსირდა.',
                    ),
                onError: () => toast.error('ვერ შეინახა.'),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="თანხმობები" />

            <h1 className="mb-2 text-2xl">თანხმობები</h1>
            <p className="mb-6 text-sm text-slate-500">
                სკოლის მიერ გამოქვეყნებული თანხმობის ფორმები თქვენი შვილ(ებ)ის
                შესახებ.
            </p>

            {children.length === 0 ? (
                <Empty className="rounded-xl border border-slate-200 bg-white p-8">
                    <EmptyHeader>
                        <FileCheck2 />
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
                            <h2 className="mb-4 font-semibold">{child.name}</h2>

                            {child.forms.length === 0 ? (
                                <p className="py-4 text-center text-sm text-slate-500">
                                    ჯერ არცერთი თანხმობის ფორმა არ არის
                                    გამოქვეყნებული.
                                </p>
                            ) : (
                                <ul className="space-y-4">
                                    {child.forms.map((form) => (
                                        <li
                                            key={form.id}
                                            className="rounded-lg border border-slate-100 p-4"
                                        >
                                            <div className="mb-2 flex items-start justify-between gap-3">
                                                <p className="font-medium">
                                                    {form.title}
                                                </p>
                                                {form.granted === null ? (
                                                    <Badge variant="outline">
                                                        პასუხგაუცემელი
                                                    </Badge>
                                                ) : form.granted ? (
                                                    <Badge className="bg-emerald-600 text-white">
                                                        თანხმობა მიცემულია
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="destructive">
                                                        უარყოფილია
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="mb-3 text-sm whitespace-pre-line text-slate-600">
                                                {form.body}
                                            </p>
                                            {form.closesAt && (
                                                <p className="mb-3 text-sm text-slate-500">
                                                    {form.isClosed
                                                        ? 'პასუხის ვადა ამოიწურა'
                                                        : 'პასუხის ბოლო ვადა'}
                                                    :{' '}
                                                    {formatDateTime(
                                                        form.closesAt,
                                                    )}
                                                </p>
                                            )}
                                            <div
                                                className={`flex gap-2 ${form.isClosed ? 'hidden' : ''}`}
                                            >
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        form.granted === true
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        respond(
                                                            form.id,
                                                            child.id,
                                                            true,
                                                        )
                                                    }
                                                >
                                                    ვეთანხმები
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        form.granted === false
                                                            ? 'destructive'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        respond(
                                                            form.id,
                                                            child.id,
                                                            false,
                                                        )
                                                    }
                                                >
                                                    {form.granted === true
                                                        ? 'თანხმობის გაუქმება'
                                                        : 'არ ვეთანხმები'}
                                                </Button>
                                            </div>
                                            {form.history.length > 0 && (
                                                <details className="mt-3 text-sm">
                                                    <summary className="flex min-h-11 cursor-pointer items-center text-slate-600">
                                                        პასუხების ისტორია
                                                    </summary>
                                                    <ul className="mt-1 space-y-1 text-slate-600">
                                                        {form.history.map(
                                                            (h, index) => (
                                                                <li key={index}>
                                                                    {DECISIONS[
                                                                        h
                                                                            .decision
                                                                    ] ??
                                                                        h.decision}{' '}
                                                                    ·{' '}
                                                                    {
                                                                        h.guardianName
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {h.createdAt
                                                                        ? formatDateTime(
                                                                              h.createdAt,
                                                                          )
                                                                        : ''}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </details>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </div>
            )}
        </PortalLayout>
    );
}
