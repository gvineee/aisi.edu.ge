import { Head, Link } from '@inertiajs/react';
import { Bus, Star, UtensilsCrossed } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type Props = {
    modules: { key: 'clubs' | 'transport' | 'meals'; href: string }[];
};

const META = {
    clubs: {
        title: 'კლუბები',
        body: 'წრეები, განრიგი და რეგისტრაცია',
        icon: Star,
    },
    transport: {
        title: 'ტრანსპორტი',
        body: 'მარშრუტი, გაჩერება და დღის სტატუსი',
        icon: Bus,
    },
    meals: {
        title: 'კვება',
        body: 'კვირის მენიუ და ალერგენები',
        icon: UtensilsCrossed,
    },
} as const;

/** Hub for the operations modules this school has switched on. */
export default function ServicesIndex({ modules }: Props) {
    return (
        <PortalLayout>
            <Head title="სერვისები" />
            <h1 className="mb-6 text-2xl">სერვისები</h1>
            {modules.length === 0 ? (
                <p className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm">
                    ამ სკოლისთვის დამატებითი სერვისი ჯერ ჩართული არ არის.
                </p>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {modules.map((module) => {
                        const meta = META[module.key];
                        const Icon = meta.icon;

                        return (
                            <li key={module.key}>
                                <Link
                                    href={module.href}
                                    className="flex h-full flex-col gap-2 rounded-2xl bg-white p-6 ring-1 ring-slate-200 hover:ring-slate-300 focus-visible:ring-2 focus-visible:ring-[var(--brand-accent)] focus-visible:outline-none"
                                >
                                    <Icon
                                        className="text-[#c84925]"
                                        aria-hidden
                                    />
                                    <span className="text-lg font-semibold">
                                        {meta.title}
                                    </span>
                                    <span className="text-sm text-slate-500">
                                        {meta.body}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </PortalLayout>
    );
}
