import { Head, Link } from '@inertiajs/react';
import { FileText, ScrollText } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDateTime } from '@/lib/assessment';

type Props = {
    student: { id: number; name: string };
    publications: {
        id: number;
        periodName: string;
        academicYearName: string | null;
        revision: number;
        publishedAt: string;
    }[];
};

/**
 * A student's published report cards — only the latest published revision
 * of each card, read from its frozen snapshot.
 */
export default function StudentReportCards({ student, publications }: Props) {
    return (
        <PortalLayout>
            <Head title={`${student.name} — ტაბელები`} />
            <h1 className="mb-6 text-2xl">{student.name} — ტაბელები</h1>

            {publications.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <ScrollText className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        გამოქვეყნებული ტაბელი ჯერ არ არის.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {publications.map((p) => (
                        <li key={p.id}>
                            <Link
                                href={`/portal/report-card-documents/${p.id}`}
                                className="flex min-h-11 items-center gap-3 p-4 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                            >
                                <FileText
                                    size={18}
                                    className="shrink-0 text-slate-500"
                                    aria-hidden
                                />
                                <span>
                                    <span className="block font-medium">
                                        {p.periodName}
                                        {p.academicYearName
                                            ? ` · ${p.academicYearName}`
                                            : ''}
                                    </span>
                                    <span className="block text-sm text-slate-500">
                                        გამოქვეყნდა{' '}
                                        {formatDateTime(p.publishedAt)}
                                        {p.revision > 1
                                            ? ` · რევიზია ${p.revision}`
                                            : ''}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
