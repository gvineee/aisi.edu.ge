import { Head, Link } from '@inertiajs/react';
import { Gavel } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import * as DocumentController from '@/actions/App/Http/Controllers/Portal/DocumentController';

type WorklistEntry = {
    id: number;
    documentId: number;
    documentTitle: string;
    workspaceTitle: string;
    authorName: string;
    ordinal: number;
    submittedAt: string | null;
    canDecide: boolean;
};

type Props = { requests: WorklistEntry[] };

export default function DirectorWorklist({ requests }: Props) {
    return (
        <PortalLayout>
            <Head title="დირექტორის სამუშაო სია" />

            <h1 className="mb-6 text-2xl">დირექტორის სამუშაო სია</h1>

            {requests.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <Gavel className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        განსახილველი დოკუმენტი არ არის.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {requests.map((entry) => (
                        <li key={entry.id} className="p-4">
                            <Link
                                href={DocumentController.show.url(
                                    entry.documentId,
                                )}
                                className="flex flex-col gap-1"
                            >
                                <p className="font-medium">
                                    {entry.documentTitle} (v{entry.ordinal})
                                </p>
                                <p className="text-sm text-slate-500">
                                    {entry.workspaceTitle} · ავტორი:{' '}
                                    {entry.authorName}
                                </p>
                                {!entry.canDecide && (
                                    <p className="text-sm text-red-600">
                                        თქვენ გამოგზავნეთ ეს ვერსია — ვერ
                                        დაამტკიცებთ საკუთარ დოკუმენტს.
                                    </p>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
