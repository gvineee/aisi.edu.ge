import { Head, Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import * as DocumentController from '@/actions/App/Http/Controllers/Portal/DocumentController';

type WorkItem = {
    id: number;
    title: string;
    status: string;
    workspaceTitle: string;
    updatedAt: string | null;
    nextAction: 'submit' | 'revise' | 'wait' | 'none';
};

type Props = { documents: WorkItem[] };

const nextActionLabels: Record<WorkItem['nextAction'], string> = {
    submit: 'გაგზავნეთ განსახილველად',
    revise: 'შეასწორეთ და თავიდან გაგზავნეთ',
    wait: 'დირექტორის გადაწყვეტილების მოლოდინში',
    none: 'დამტკიცებულია',
};

export default function MyDocumentWork({ documents }: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი სამუშაო" />

            <h1 className="mb-6 text-2xl">ჩემი სამუშაო</h1>

            {documents.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <ClipboardList className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        ამჟამად თქვენი დოკუმენტი არ არსებობს.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {documents.map((document) => (
                        <li key={document.id} className="p-4">
                            <Link
                                href={DocumentController.show.url(document.id)}
                                className="flex flex-col gap-1"
                            >
                                <p className="font-medium">{document.title}</p>
                                <p className="text-sm text-slate-500">
                                    {document.workspaceTitle}
                                </p>
                                <p className="text-sm text-[var(--brand-accent)]">
                                    {nextActionLabels[document.nextAction]}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
