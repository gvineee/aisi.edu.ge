import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import Logo from '@/components/public/logo';
import { formatDateTime, formatDay, formatPercent } from '@/lib/assessment';
import type { Brand } from '@/types';

type Snapshot = {
    schoolName: string;
    studentName: string;
    className: string;
    academicYearName: string | null;
    periodName: string;
    periodStartsOn: string;
    periodEndsOn: string;
    subjects: {
        subject: string;
        finalMark: string | null;
        teacherComment: string | null;
        assessmentsCount: number;
        averagePercent: string | null;
    }[];
    homeroomComment: string | null;
    directorComment: string | null;
    revision: number;
    publishedAt: string;
    verificationCode: string;
};

type Props = {
    document: Snapshot;
    isLatest: boolean;
    verificationUrl: string;
    pdfUrl: string;
    studentId: number;
};

/**
 * Printable report card. Everything shown comes from the frozen
 * publication snapshot; the brand logo comes from tenant settings. The PDF
 * download is rendered on the server from the same snapshot (ReportCardPdf);
 * "Print" uses the browser.
 */
export default function ReportCardDocument({
    document: doc,
    isLatest,
    verificationUrl,
    pdfUrl,
    studentId,
}: Props) {
    const { brand } = usePage<{ brand: Brand | null }>().props;

    return (
        <div className="min-h-screen bg-slate-100 px-4 py-6 text-slate-900 print:bg-white print:p-0">
            <Head title={`ტაბელი — ${doc.studentName}`} />

            <div className="mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 print:hidden">
                <Link
                    href={`/portal/students/${studentId}/report-cards`}
                    className="inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
                >
                    <ArrowLeft size={16} /> ტაბელები
                </Link>
                <div className="flex flex-wrap gap-2">
                    <a
                        href={pdfUrl}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm font-medium ring-1 ring-slate-300 hover:bg-slate-50"
                    >
                        <Download size={16} aria-hidden /> PDF ჩამოტვირთვა
                    </a>
                    <Button onClick={() => window.print()}>
                        <Printer size={16} /> ბეჭდვა
                    </Button>
                </div>
            </div>

            {!isLatest && (
                <p
                    role="status"
                    className="mx-auto mb-4 max-w-3xl rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    ეს რევიზია ჩანაცვლებულია უფრო ახალი გამოქვეყნებული ვერსიით.
                </p>
            )}

            <article className="mx-auto max-w-3xl rounded-xl bg-white p-6 shadow-sm sm:p-10 print:max-w-none print:rounded-none print:shadow-none">
                <header className="mb-8 flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-6">
                    <div>
                        <Logo brand={brand} size="compact" />
                        <p className="mt-2 text-sm text-slate-600">
                            {doc.schoolName}
                        </p>
                    </div>
                    <div className="text-right text-sm">
                        <p className="text-lg font-semibold">ტაბელი</p>
                        <p>
                            {doc.periodName}
                            {doc.academicYearName
                                ? ` · ${doc.academicYearName}`
                                : ''}
                        </p>
                        <p className="text-slate-500">
                            {formatDay(doc.periodStartsOn)} –{' '}
                            {formatDay(doc.periodEndsOn)}
                        </p>
                    </div>
                </header>

                <dl className="mb-8 grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-slate-500">მოსწავლე</dt>
                        <dd className="text-base font-medium">
                            {doc.studentName}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">კლასი</dt>
                        <dd className="text-base font-medium">
                            {doc.className}
                        </dd>
                    </div>
                </dl>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[480px] border-collapse text-sm">
                        <caption className="sr-only">
                            საგნების შეფასებები
                        </caption>
                        <thead>
                            <tr className="border-b-2 border-slate-300 text-left">
                                <th scope="col" className="py-2 pr-3">
                                    საგანი
                                </th>
                                <th scope="col" className="py-2 pr-3">
                                    შეფასება
                                </th>
                                <th scope="col" className="py-2">
                                    კომენტარი
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {doc.subjects.map((s) => (
                                <tr
                                    key={s.subject}
                                    className="border-b border-slate-200 align-top"
                                >
                                    <th
                                        scope="row"
                                        className="py-3 pr-3 text-left font-medium"
                                    >
                                        {s.subject}
                                        {s.assessmentsCount > 0 && (
                                            <span className="block text-xs font-normal text-slate-500">
                                                {s.assessmentsCount} შეფასება ·
                                                საშ.{' '}
                                                {formatPercent(
                                                    s.averagePercent,
                                                )}
                                            </span>
                                        )}
                                    </th>
                                    <td className="py-3 pr-3 text-base font-semibold">
                                        {s.finalMark ?? '—'}
                                    </td>
                                    <td className="py-3 whitespace-pre-wrap">
                                        {s.teacherComment ?? ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {(doc.homeroomComment || doc.directorComment) && (
                    <section className="mt-8 space-y-3 text-sm">
                        {doc.homeroomComment && (
                            <p>
                                <span className="font-medium">
                                    დამრიგებლის შეფასება:{' '}
                                </span>
                                {doc.homeroomComment}
                            </p>
                        )}
                        {doc.directorComment && (
                            <p>
                                <span className="font-medium">
                                    დირექტორის კომენტარი:{' '}
                                </span>
                                {doc.directorComment}
                            </p>
                        )}
                    </section>
                )}

                <footer className="mt-10 border-t border-slate-200 pt-4 text-xs text-slate-500">
                    <p>
                        რევიზია {doc.revision} · გამოქვეყნდა{' '}
                        {formatDateTime(doc.publishedAt)}
                    </p>
                    <p className="mt-1 break-all">
                        შემოწმების კოდი:{' '}
                        <strong className="font-mono text-slate-700">
                            {doc.verificationCode}
                        </strong>{' '}
                        · {verificationUrl}
                    </p>
                    <p className="mt-1">
                        საშუალო პროცენტი ინფორმაციულია; ოფიციალურია „შეფასების“
                        სვეტი.
                    </p>
                </footer>
            </article>
        </div>
    );
}
