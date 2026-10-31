import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, House } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';

type Props = { status: number };

const MESSAGES: Record<number, { title: string; body: string }> = {
    403: {
        title: 'წვდომა შეზღუდულია',
        body: 'ამ გვერდის ნახვის უფლება თქვენს ანგარიშს არ აქვს. თუ ფიქრობთ, რომ უნდა გქონდეთ, მიმართეთ სკოლის ადმინისტრაციას.',
    },
    404: {
        title: 'გვერდი ვერ მოიძებნა',
        body: 'ბმული შეიძლება შეიცვალა ან გვერდი აღარ არსებობს.',
    },
    419: {
        title: 'გვერდის ვადა ამოიწურა',
        body: 'გვერდი დიდხანს იყო ღია, ამიტომ ფორმა ვერ გაიგზავნა. განაახლეთ გვერდი და სცადეთ თავიდან.',
    },
    429: {
        title: 'ძალიან ბევრი მოთხოვნა',
        body: 'მოკლე დროში ძალიან ბევრი მცდელობა იყო. მოიცადეთ ერთი წუთი და სცადეთ თავიდან.',
    },
    500: {
        title: 'რაღაც შეფერხდა',
        body: 'ჩვენი მხრიდან მოხდა შეცდომა. სცადეთ ცოტა ხანში; თუ განმეორდა, შეატყობინეთ სკოლას.',
    },
    503: {
        title: 'მიმდინარეობს განახლება',
        body: 'საიტი მალე დაბრუნდება. სცადეთ რამდენიმე წუთში.',
    },
};

/**
 * The school's own error screen. It says what happened and offers a way
 * back; it never says more about the record that was refused (a 403/404
 * looks the same whether or not the thing exists).
 */
export default function ErrorPage({ status }: Props) {
    const { auth } = usePage<{ auth: { user: unknown } }>().props;
    const message = MESSAGES[status] ?? MESSAGES[500];

    return (
        <PublicLayout>
            <Head title={message.title}>
                <meta name="robots" content="noindex" />
            </Head>
            <section className="public-wrap py-16 sm:py-24">
                <p className="public-eyebrow">შეცდომა {status}</p>
                <h1 className="text-3xl sm:text-4xl">{message.title}</h1>
                <p className="mt-4 max-w-xl text-lg text-slate-600">
                    {message.body}
                </p>
                <div className="mt-8 flex flex-wrap gap-3">
                    <button
                        type="button"
                        onClick={() => window.history.back()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg px-4 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50"
                    >
                        <ArrowLeft size={16} aria-hidden /> უკან
                    </button>
                    <Link
                        href="/"
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg px-4 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50"
                    >
                        <House size={16} aria-hidden /> მთავარი გვერდი
                    </Link>
                    <Link
                        href={auth.user ? '/dashboard' : '/login'}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-[var(--brand-primary)] px-4 text-sm font-semibold text-white"
                    >
                        {auth.user ? 'ჩემი სივრცე' : 'შესვლა'}
                        <ArrowUpRight size={16} aria-hidden />
                    </Link>
                </div>
            </section>
        </PublicLayout>
    );
}
