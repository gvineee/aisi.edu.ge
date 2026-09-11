import { Link, usePage } from '@inertiajs/react';
import { BookOpen, CalendarDays, ShieldCheck } from 'lucide-react';
import Logo from '@/components/public/logo';
import { home } from '@/routes';
import type { AuthLayoutProps, Brand } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { brand } = usePage<{ brand: Brand | null }>().props;

    return (
        <div className="grid min-h-svh bg-[#f3f6f8] lg:grid-cols-[minmax(390px,0.9fr)_1.1fr]">
            <aside className="relative hidden overflow-hidden bg-[#132b45] p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14">
                <div className="absolute -top-28 -right-24 h-80 w-80 rounded-full border-[58px] border-white/[0.04]" />
                <div className="absolute -bottom-40 -left-28 h-96 w-96 rounded-full border-[70px] border-[#f5683c]/10" />

                <Link
                    href={home()}
                    className="relative z-10 w-fit rounded-xl bg-white px-5 py-2 shadow-lg shadow-black/10"
                    aria-label={`${brand?.name ?? 'აისი'} — მთავარი გვერდი`}
                >
                    <Logo brand={brand} size="compact" />
                </Link>

                <div className="relative z-10 max-w-lg py-12">
                    <p className="mb-5 text-sm font-bold tracking-[0.08em] text-[#ff9d7c]">
                        ჩემი აისი
                    </p>
                    <h2 className="text-[2.55rem] leading-[1.35] text-white xl:text-5xl">
                        შენი სასკოლო დღე — ერთ მშვიდ სივრცეში.
                    </h2>
                    <p className="mt-6 max-w-md text-base leading-8 text-slate-300">
                        განრიგი, სასწავლო მასალები, შეტყობინებები და სკოლის
                        სერვისები შენს როლზე მორგებულად.
                    </p>
                </div>

                <div className="relative z-10 grid gap-3 text-sm text-slate-200 xl:grid-cols-3">
                    <span className="flex items-center gap-2">
                        <CalendarDays className="size-4 text-[#ff8d68]" />
                        დღის განრიგი
                    </span>
                    <span className="flex items-center gap-2">
                        <BookOpen className="size-4 text-[#ff8d68]" />
                        სასწავლო სივრცე
                    </span>
                    <span className="flex items-center gap-2">
                        <ShieldCheck className="size-4 text-[#ff8d68]" />
                        დაცული წვდომა
                    </span>
                </div>
            </aside>

            <main className="flex min-h-svh items-center justify-center px-5 py-8 sm:px-8 lg:px-12">
                <div className="w-full max-w-[460px]">
                    <div className="mb-8 flex justify-center lg:hidden">
                        <Link
                            href={home()}
                            className="rounded-xl bg-white px-4 py-2 shadow-sm"
                            aria-label={`${brand?.name ?? 'აისი'} — მთავარი გვერდი`}
                        >
                            <Logo brand={brand} size="compact" />
                        </Link>
                    </div>

                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_22px_70px_rgba(19,43,69,0.10)]">
                        <div className="h-1.5 bg-[#f5683c]" />
                        <div className="p-6 sm:p-9">
                            <div className="mb-8 space-y-3">
                                <p className="text-sm font-bold text-[#f5683c]">
                                    დაცული ავტორიზაცია
                                </p>
                                <h1 className="text-3xl leading-snug text-[#132b45]">
                                    {title}
                                </h1>
                                <p className="text-sm leading-6 text-slate-500">
                                    {description}
                                </p>
                            </div>
                            {children}
                        </div>
                    </section>

                    <p className="mt-6 text-center text-xs leading-5 text-slate-500">
                        სისტემაში შესვლით თქვენ იყენებთ სკოლის დაცულ ციფრულ
                        სივრცეს.
                    </p>
                </div>
            </main>
        </div>
    );
}
