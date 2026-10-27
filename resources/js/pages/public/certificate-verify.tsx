import { Head } from '@inertiajs/react';
import { BadgeCheck, CircleAlert } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { formatDay } from '@/lib/assessment';

type Props = {
    result: {
        schoolName: string;
        kind: string;
        title: string;
        number: string;
        issuedOn: string;
        holderInitials: string;
        isRevoked: boolean;
    } | null;
};

const KINDS: Record<string, string> = {
    certificate: 'ცნობა',
    diploma: 'დიპლომი / სიგელი',
    letter: 'წერილი',
};

/**
 * Public authenticity check for a printed certificate, diploma or letter.
 * Shows no text and only the holder's initials.
 */
export default function CertificateVerify({ result }: Props) {
    return (
        <PublicLayout>
            <Head title="დოკუმენტის შემოწმება">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <section className="mx-auto max-w-xl px-4 py-16">
                <h1 className="mb-6 text-3xl">დოკუმენტის შემოწმება</h1>

                {result === null ? (
                    <div
                        role="status"
                        className="flex gap-3 rounded-xl bg-red-50 p-6 text-red-900"
                    >
                        <CircleAlert className="shrink-0" aria-hidden />
                        <p>
                            ამ კოდით გაცემული დოკუმენტი ვერ მოიძებნა.
                            გადაამოწმეთ კოდი ან დაუკავშირდით სკოლას.
                        </p>
                    </div>
                ) : (
                    <div
                        role="status"
                        className="space-y-4 rounded-xl bg-white p-6 ring-1 ring-slate-200"
                    >
                        {result.isRevoked ? (
                            <p className="flex items-center gap-2 text-lg font-medium text-red-800">
                                <CircleAlert aria-hidden /> დოკუმენტი
                                გაუქმებულია და ძალაში აღარ არის
                            </p>
                        ) : (
                            <p className="flex items-center gap-2 text-lg font-medium text-emerald-800">
                                <BadgeCheck aria-hidden /> დოკუმენტი ნამდვილია
                                და ძალაშია
                            </p>
                        )}
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">სკოლა</dt>
                                <dd>{result.schoolName}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">სახე</dt>
                                <dd>{KINDS[result.kind] ?? result.kind}</dd>
                            </div>
                            <div className="sm:col-span-2">
                                <dt className="text-slate-500">დასახელება</dt>
                                <dd>{result.title}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">ვის გაეცა</dt>
                                <dd>{result.holderInitials}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">
                                    ნომერი და თარიღი
                                </dt>
                                <dd>
                                    № {result.number},{' '}
                                    {formatDay(result.issuedOn)}
                                </dd>
                            </div>
                        </dl>
                        <p className="text-xs text-slate-500">
                            კონფიდენციალურობის მიზნით დოკუმენტის ტექსტი აქ არ
                            ჩანს.
                        </p>
                    </div>
                )}
            </section>
        </PublicLayout>
    );
}
