import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Badge } from '@/components/ui/badge';

type RosterRow = {
    studentId: number;
    studentName: string;
    status: 'granted' | 'denied' | 'pending';
    respondedAt: string | null;
};

type Props = {
    form: { id: number; title: string };
    rows: RosterRow[];
};

const STATUS_LABEL: Record<RosterRow['status'], string> = {
    granted: 'თანხმობა მიცემულია',
    denied: 'უარყოფილია',
    pending: 'მოლოდინში',
};

export default function ConsentsRoster({ form, rows }: Props) {
    return (
        <PortalLayout>
            <Head title={`თანხმობები — ${form.title}`} />

            <Link
                href="/portal/consents"
                className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
            >
                <ArrowLeft size={16} /> ყველა ფორმა
            </Link>

            <h1 className="mb-6 text-2xl">{form.title}</h1>

            {rows.length === 0 ? (
                <p className="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">
                    ამჟამად აქტიური მოსწავლე არ არის.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-100 text-slate-500">
                            <tr>
                                <th className="p-3 font-medium">მოსწავლე</th>
                                <th className="p-3 font-medium">სტატუსი</th>
                                <th className="p-3 font-medium">თარიღი</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((row) => (
                                <tr key={row.studentId}>
                                    <td className="p-3">
                                        {row.studentName}
                                    </td>
                                    <td className="p-3">
                                        {row.status === 'granted' ? (
                                            <Badge className="bg-emerald-600 text-white">
                                                {STATUS_LABEL[row.status]}
                                            </Badge>
                                        ) : row.status === 'denied' ? (
                                            <Badge variant="destructive">
                                                {STATUS_LABEL[row.status]}
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                {STATUS_LABEL[row.status]}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3 text-slate-500">
                                        {row.respondedAt
                                            ? new Date(
                                                  row.respondedAt,
                                              ).toLocaleDateString('ka-GE', {
                                                  day: 'numeric',
                                                  month: 'long',
                                                  year: 'numeric',
                                              })
                                            : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </PortalLayout>
    );
}
