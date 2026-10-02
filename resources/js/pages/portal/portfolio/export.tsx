import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import Logo from '@/components/public/logo';
import { formatDateTime, formatDay } from '@/lib/assessment';
import type { Brand } from '@/types';

type Props = {
    studentId: number;
    student: string;
    items: {
        id: number;
        title: string;
        description: string | null;
        subject: string | null;
        publishedAt: string | null;
        tags: string[];
        feedback: string | null;
    }[];
    achievements: {
        title: string;
        description: string | null;
        awardedOn: string;
    }[];
    checksum: string;
    generatedAt: string;
};

/**
 * Printable portfolio of published work and shared achievements. The
 * export is recorded server-side with its checksum.
 */
export default function PortfolioExport({
    studentId,
    student,
    items,
    achievements,
    checksum,
    generatedAt,
}: Props) {
    const { brand } = usePage<{ brand: Brand | null }>().props;

    return (
        <div className="min-h-screen bg-slate-100 px-4 py-6 print:bg-white print:p-0">
            <Head title={`პორტფოლიო — ${student}`} />
            <div className="mx-auto mb-4 flex max-w-3xl items-center justify-between gap-3 print:hidden">
                <Link
                    href={`/portal/students/${studentId}/portfolio`}
                    className="inline-flex min-h-11 items-center gap-2 text-sm text-slate-600"
                >
                    <ArrowLeft size={16} /> პორტფოლიო
                </Link>
                <Button onClick={() => window.print()}>
                    <Printer size={16} /> ბეჭდვა / PDF
                </Button>
            </div>
            <article className="mx-auto max-w-3xl rounded-xl bg-white p-6 shadow-sm sm:p-10 print:shadow-none">
                <header className="mb-8 flex items-start justify-between gap-4 border-b border-slate-200 pb-6">
                    <Logo brand={brand} size="compact" />
                    <div className="text-right">
                        <p className="text-lg font-semibold">პორტფოლიო</p>
                        <p>{student}</p>
                    </div>
                </header>
                {items.length === 0 && (
                    <p className="text-sm text-slate-500">
                        გამოქვეყნებული ნამუშევარი ჯერ არ არის.
                    </p>
                )}
                <ol className="space-y-6">
                    {items.map((item) => (
                        <li key={item.id} className="break-inside-avoid">
                            <h2 className="text-lg font-semibold">
                                {item.title}
                            </h2>
                            <p className="text-sm text-slate-500">
                                {[
                                    item.subject,
                                    item.publishedAt
                                        ? formatDay(
                                              item.publishedAt.slice(0, 10),
                                          )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                                {item.tags.length > 0
                                    ? ` · ${item.tags.map((t) => `#${t}`).join(' ')}`
                                    : ''}
                            </p>
                            {item.description && (
                                <p className="mt-2 whitespace-pre-wrap">
                                    {item.description}
                                </p>
                            )}
                            {item.feedback && (
                                <p className="mt-2 border-l-4 border-slate-200 pl-3 text-sm text-slate-700">
                                    მასწავლებელი: {item.feedback}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
                {achievements.length > 0 && (
                    <section className="mt-8">
                        <h2 className="mb-2 text-lg font-semibold">
                            მიღწევები
                        </h2>
                        <ul className="space-y-1">
                            {achievements.map((a, i) => (
                                <li key={i}>
                                    {formatDay(a.awardedOn)} —{' '}
                                    <strong>{a.title}</strong>
                                    {a.description ? `: ${a.description}` : ''}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <footer className="mt-10 border-t border-slate-200 pt-4 text-xs break-all text-slate-500">
                    შექმნილია {formatDateTime(generatedAt)} · checksum{' '}
                    {checksum}
                </footer>
            </article>
        </div>
    );
}
