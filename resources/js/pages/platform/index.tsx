import { Head, Link } from '@inertiajs/react';
import { type FormEvent } from 'react';
import PlatformLayout from '@/layouts/platform-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postWithToast } from '@/lib/operations';

type Props = {
    tenants: {
        id: number;
        slug: string;
        name: string;
        isActive: boolean;
        primaryDomain: string | null;
        pendingSteps: number;
    }[];
    plans: {
        id: number;
        code: string;
        name: string;
        priceMinor: number | null;
        currency: string;
        billingPeriod: string | null;
        features: string[];
    }[];
    features: string[];
};

const submit =
    (url: string, success: string) => (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        postWithToast(url, new FormData(form), success, () => form.reset());
    };

/** Tenants and plans overview for platform operators. */
export default function PlatformIndex({ tenants, plans, features }: Props) {
    return (
        <PlatformLayout>
            <Head title="პლატფორმა" />
            <h1 className="mb-6 text-2xl">სკოლები</h1>

            <form
                onSubmit={submit('/platform-admin/tenants', 'სკოლა შეიქმნა.')}
                className="mb-6 grid gap-3 rounded-xl bg-white p-5 ring-1 ring-slate-200 sm:grid-cols-4 sm:items-end"
            >
                <div>
                    <label
                        htmlFor="t-slug"
                        className="mb-1 block text-sm font-medium"
                    >
                        იდენტიფიკატორი
                    </label>
                    <Input
                        id="t-slug"
                        name="slug"
                        required
                        pattern="[a-z0-9-]+"
                        maxLength={40}
                    />
                </div>
                <div>
                    <label
                        htmlFor="t-name"
                        className="mb-1 block text-sm font-medium"
                    >
                        დასახელება
                    </label>
                    <Input id="t-name" name="name" required maxLength={255} />
                </div>
                <div>
                    <label
                        htmlFor="t-domain"
                        className="mb-1 block text-sm font-medium"
                    >
                        ძირითადი დომენი
                    </label>
                    <Input
                        id="t-domain"
                        name="domain"
                        required
                        placeholder="school.example.ge"
                    />
                </div>
                <Button type="submit">ახალი სკოლა</Button>
            </form>

            <ul className="mb-10 divide-y divide-slate-200 rounded-xl bg-white ring-1 ring-slate-200">
                {tenants.map((t) => (
                    <li key={t.id}>
                        <Link
                            href={`/platform-admin/tenants/${t.id}`}
                            className="flex min-h-11 flex-wrap items-center justify-between gap-2 p-4 hover:bg-slate-50"
                        >
                            <span>
                                <strong>{t.name}</strong>{' '}
                                <span className="text-slate-500">
                                    ({t.slug})
                                </span>
                                <span className="block text-sm text-slate-500">
                                    {t.primaryDomain ?? 'დომენი არ არის'}
                                </span>
                            </span>
                            <span className="text-sm">
                                {t.isActive ? 'აქტიური' : 'არააქტიური'}
                                {t.pendingSteps > 0
                                    ? ` · onboarding: ${t.pendingSteps} ნაბიჯი დარჩა`
                                    : ''}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>

            <h2 className="mb-3 text-xl">გეგმები</h2>
            <p className="mb-3 text-sm text-slate-600">
                ფასები კომერციული პირობების განსაზღვრამდე არ ივსება.
            </p>
            <form
                onSubmit={submit('/platform-admin/plans', 'გეგმა შეიქმნა.')}
                className="mb-4 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                        aria-label="კოდი"
                        name="code"
                        required
                        pattern="[a-z0-9_-]+"
                        placeholder="კოდი"
                    />
                    <Input
                        aria-label="დასახელება"
                        name="name"
                        required
                        placeholder="დასახელება"
                    />
                </div>
                <fieldset className="flex flex-wrap gap-3 text-sm">
                    <legend className="mb-1 font-medium">მოდულები</legend>
                    {features.map((f) => (
                        <label
                            key={f}
                            className="flex min-h-11 items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                name="features[]"
                                value={f}
                                className="h-4 w-4"
                            />{' '}
                            {f}
                        </label>
                    ))}
                </fieldset>
                <Button type="submit">გეგმის დამატება</Button>
            </form>
            <ul className="space-y-2 text-sm">
                {plans.map((p) => (
                    <li
                        key={p.id}
                        className="rounded-lg bg-white p-3 ring-1 ring-slate-200"
                    >
                        <strong>{p.name}</strong> ({p.code}) ·{' '}
                        {p.priceMinor === null
                            ? 'ფასი განსაზღვრული არ არის'
                            : `${p.priceMinor / 100} ${p.currency}`}{' '}
                        · {p.features.join(', ') || '—'}
                    </li>
                ))}
            </ul>
        </PlatformLayout>
    );
}
