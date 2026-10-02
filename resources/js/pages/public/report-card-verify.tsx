import { Head } from '@inertiajs/react';
import { BadgeCheck, CircleAlert } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { formatDateTime } from '@/lib/assessment';

type Props = {
    result: {
        schoolName: string | null;
        studentInitials: string;
        className: string | null;
        academicYearName: string | null;
        periodName: string | null;
        revision: number;
        publishedAt: string;
        isLatest: boolean;
    } | null;
};

/**
 * Public authenticity check for a printed report card. Shows no marks and
 * only the student's initials.
 */
export default function ReportCardVerify({ result }: Props) {
    return (
        <PublicLayout>
            <Head title="ტაბელის შემოწმება">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <section className="mx-auto max-w-xl px-4 py-16">
                <h1 className="mb-6 text-3xl">ტაბელის შემოწმება</h1>

                {result === null ? (
                    <div
                        role="status"
                        className="flex gap-3 rounded-xl bg-red-50 p-6 text-red-900"
                    >
                        <CircleAlert className="shrink-0" aria-hidden />
                        <p>
                            ამ კოდით გამოქვეყნებული ტაბელი ვერ მოიძებნა.
                            გადაამოწმეთ კოდი ან დაუკავშირდით სკოლას.
                        </p>
                    </div>
                ) : (
                    <div
                        role="status"
                        className="space-y-4 rounded-xl bg-white p-6 ring-1 ring-slate-200"
                    >
                        <p className="flex items-center gap-2 text-lg font-medium text-emerald-800">
                            <BadgeCheck aria-hidden /> ტაბელი ნამდვილია
                        </p>
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">სკოლა</dt>
                                <dd>{result.schoolName}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">მოსწავლე</dt>
                                <dd>{result.studentInitials}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">კლასი</dt>
                                <dd>{result.className}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">პერიოდი</dt>
                                <dd>
                                    {result.periodName}
                                    {result.academicYearName
                                        ? ` · ${result.academicYearName}`
                                        : ''}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">რევიზია</dt>
                                <dd>{result.revision}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">გამოქვეყნდა</dt>
                                <dd>{formatDateTime(result.publishedAt)}</dd>
                            </div>
                        </dl>
                        {!result.isLatest && (
                            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                                ყურადღება: ამ ტაბელს უფრო ახალი, შესწორებული
                                რევიზია აქვს. აქტუალური ვერსია სკოლას მოსთხოვეთ.
                            </p>
                        )}
                        <p className="text-xs text-slate-500">
                            კონფიდენციალურობის მიზნით შეფასებები აქ არ ჩანს.
                        </p>
                    </div>
                )}
            </section>
        </PublicLayout>
    );
}
