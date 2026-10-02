import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { ArrowLeft, CheckCircle2, Circle } from 'lucide-react';
import PlatformLayout from '@/layouts/platform-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/assessment';
import { postWithToast } from '@/lib/operations';

type Props = {
    tenant: {
        id: number;
        slug: string;
        name: string;
        isActive: boolean;
        locale: string;
        timezone: string;
        brand: {
            displayName: string;
            shortName: string | null;
            colors: Record<string, string>;
            hasLogo: boolean;
            hasDarkLogo: boolean;
            hasIcon: boolean;
        } | null;
    };
    domains: {
        id: number;
        domain: string;
        isPrimary: boolean;
        verified: boolean;
        txtName: string;
        token: string | null;
    }[];
    steps: { step: string; done: boolean }[];
    features: { feature: string; enabled: boolean }[];
    subscription: { plan: string; status: string; startsOn: string } | null;
    plans: { id: number; name: string }[];
    supportRequests: {
        id: number;
        status: string;
        reason: string;
        minutes: number;
        expiresAt: string | null;
        isActive: boolean;
    }[];
    activeSupport: boolean;
    invitationLink: string | null;
    audit: { action: string; createdAt: string | null }[];
};

const STEP_LABELS: Record<string, string> = {
    identity: 'იდენტობა',
    domain: 'დომენი',
    brand: 'ბრენდი',
    locale: 'ენა და დროის სარტყელი',
    academic_year: 'სასწავლო წელი',
    staff_invites: 'პირველი ადმინისტრატორი',
    modules: 'მოდულები',
    review: 'შემოწმება და გააქტიურება',
};

const card = 'rounded-xl bg-white p-5 ring-1 ring-slate-200';

/**
 * One tenant: onboarding wizard (resumable), domains with DNS proof,
 * features, plan and support access. Configuration only — no school data.
 */
export default function PlatformTenant(props: Props) {
    const {
        tenant,
        domains,
        steps,
        features,
        subscription,
        plans,
        supportRequests,
        activeSupport,
        audit,
        invitationLink,
    } = props;
    const base = `/platform-admin/tenants/${tenant.id}`;

    const step =
        (name: string, multipart = false) =>
        (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            router.post(
                `${base}/onboarding/${name}`,
                new FormData(event.currentTarget),
                { preserveScroll: true, forceFormData: multipart },
            );
        };

    return (
        <PlatformLayout>
            <Head title={tenant.name} />
            <Link
                href="/platform-admin"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600"
            >
                <ArrowLeft size={16} /> სკოლები
            </Link>
            <h1 className="mb-1 text-2xl">{tenant.name}</h1>
            <p className="mb-6 text-sm text-slate-500">
                {tenant.slug} ·{' '}
                {tenant.isActive ? 'აქტიური' : 'არააქტიური (onboarding)'}
            </p>

            <div className="grid gap-6 lg:grid-cols-2">
                <section className={card} aria-labelledby="wizard">
                    <h2 id="wizard" className="mb-3 text-lg">
                        Onboarding
                    </h2>
                    <ol className="mb-4 space-y-1 text-sm">
                        {steps.map((s) => (
                            <li
                                key={s.step}
                                className="flex items-center gap-2"
                            >
                                {s.done ? (
                                    <CheckCircle2
                                        size={16}
                                        className="text-emerald-600"
                                        aria-label="დასრულებულია"
                                    />
                                ) : (
                                    <Circle
                                        size={16}
                                        className="text-slate-400"
                                        aria-label="დარჩენილია"
                                    />
                                )}
                                {STEP_LABELS[s.step] ?? s.step}
                            </li>
                        ))}
                    </ol>

                    <details className="mb-2">
                        <summary className="min-h-11 cursor-pointer leading-[44px] font-medium">
                            ბრენდი
                        </summary>
                        <form
                            onSubmit={step('brand', true)}
                            className="space-y-2"
                        >
                            <Input
                                aria-label="სახელი"
                                name="display_name"
                                required
                                defaultValue={tenant.brand?.displayName}
                            />
                            <Input
                                aria-label="მოკლე სახელი"
                                name="short_name"
                                defaultValue={tenant.brand?.shortName ?? ''}
                                placeholder="მოკლე სახელი"
                            />
                            <div className="flex flex-wrap gap-2 text-sm">
                                {['primary', 'accent', 'secondary'].map((c) => (
                                    <label
                                        key={c}
                                        className="flex min-h-11 items-center gap-2"
                                    >
                                        {c}
                                        <input
                                            type="color"
                                            name={c}
                                            defaultValue={
                                                tenant.brand?.colors[c] ??
                                                '#132B45'
                                            }
                                        />
                                    </label>
                                ))}
                            </div>
                            {[
                                ['logo', 'ლოგო (lockup)'],
                                ['logo_dark', 'ლოგო მუქ ფონზე'],
                                ['icon', 'იკონი'],
                            ].map(([name, label]) => (
                                <label key={name} className="block text-sm">
                                    {label} (PNG/JPEG/WebP ≤2MB)
                                    <input
                                        type="file"
                                        name={name}
                                        accept="image/png,image/jpeg,image/webp"
                                        className="block min-h-11"
                                    />
                                </label>
                            ))}
                            <Button type="submit" size="sm">
                                შენახვა
                            </Button>
                        </form>
                    </details>
                    <details className="mb-2">
                        <summary className="min-h-11 cursor-pointer leading-[44px] font-medium">
                            ენა და დრო
                        </summary>
                        <form
                            onSubmit={step('locale')}
                            className="flex flex-wrap gap-2"
                        >
                            <select
                                aria-label="ენა"
                                name="locale"
                                defaultValue={tenant.locale}
                                className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                            >
                                <option value="ka">ქართული</option>
                                <option value="en">English</option>
                            </select>
                            <Input
                                aria-label="დროის სარტყელი"
                                name="timezone"
                                defaultValue={tenant.timezone}
                                className="w-48"
                            />
                            <Button type="submit" size="sm">
                                შენახვა
                            </Button>
                        </form>
                    </details>
                    <details className="mb-2">
                        <summary className="min-h-11 cursor-pointer leading-[44px] font-medium">
                            სასწავლო წელი
                        </summary>
                        <form
                            onSubmit={step('academic_year')}
                            className="flex flex-wrap gap-2"
                        >
                            <Input
                                aria-label="დასახელება"
                                name="name"
                                required
                                placeholder="2026-2027"
                                className="w-32"
                            />
                            <Input
                                aria-label="დაწყება"
                                name="starts_on"
                                type="date"
                                required
                                className="w-40"
                            />
                            <Input
                                aria-label="დასრულება"
                                name="ends_on"
                                type="date"
                                required
                                className="w-40"
                            />
                            <Button type="submit" size="sm">
                                შენახვა
                            </Button>
                        </form>
                    </details>
                    <details className="mb-2">
                        <summary className="min-h-11 cursor-pointer leading-[44px] font-medium">
                            პირველი ადმინისტრატორი
                        </summary>
                        <form
                            onSubmit={step('staff_invites')}
                            className="flex flex-wrap gap-2"
                        >
                            <Input
                                aria-label="ელფოსტა"
                                name="email"
                                type="email"
                                required
                                className="flex-1"
                            />
                            <Button type="submit" size="sm">
                                მოწვევა
                            </Button>
                        </form>
                        {invitationLink && (
                            <p className="mt-2 text-sm break-all">
                                მოწვევის ბმული: {invitationLink}
                            </p>
                        )}
                    </details>
                    <details className="mb-2">
                        <summary className="min-h-11 cursor-pointer leading-[44px] font-medium">
                            მოდულები
                        </summary>
                        <form
                            onSubmit={step('modules')}
                            className="flex flex-wrap gap-3 text-sm"
                        >
                            {features.map((f) => (
                                <label
                                    key={f.feature}
                                    className="flex min-h-11 items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        name="features[]"
                                        value={f.feature}
                                        defaultChecked={f.enabled}
                                        className="h-4 w-4"
                                    />
                                    {f.feature}
                                </label>
                            ))}
                            <Button type="submit" size="sm">
                                შენახვა
                            </Button>
                        </form>
                    </details>
                    <Button
                        className="mt-3"
                        onClick={() =>
                            postWithToast(
                                `${base}/onboarding/review`,
                                {},
                                'სკოლა გააქტიურდა.',
                            )
                        }
                    >
                        შემოწმება და გააქტიურება
                    </Button>
                </section>

                <section className={card} aria-labelledby="domains">
                    <h2 id="domains" className="mb-3 text-lg">
                        დომენები
                    </h2>
                    <ul className="mb-3 space-y-3 text-sm">
                        {domains.map((d) => (
                            <li
                                key={d.id}
                                className="rounded-lg bg-slate-50 p-3"
                            >
                                <strong>{d.domain}</strong>{' '}
                                {d.isPrimary && '(ძირითადი)'} ·{' '}
                                {d.verified
                                    ? 'დადასტურებული'
                                    : 'მოლოდინში — ჯერ არაფერს ემსახურება'}
                                {!d.verified && d.token && (
                                    <>
                                        <span className="mt-1 block break-all text-slate-600">
                                            TXT {d.txtName} = {d.token}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="mt-2"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/domains/${d.id}/verify`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            DNS-ის შემოწმება
                                        </Button>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                    <form
                        className="flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            postWithToast(
                                `${base}/domains`,
                                new FormData(e.currentTarget),
                                'დომენი დაემატა.',
                            );
                        }}
                    >
                        <Input
                            aria-label="დომენი"
                            name="domain"
                            required
                            placeholder="www.school.ge"
                        />
                        <Button type="submit" size="sm">
                            დამატება
                        </Button>
                    </form>

                    <h2 className="mt-6 mb-2 text-lg">გეგმა</h2>
                    <p className="mb-2 text-sm">
                        {subscription
                            ? `${subscription.plan} · ${subscription.status} · ${subscription.startsOn}-დან`
                            : 'გეგმა მინიჭებული არ არის.'}
                    </p>
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            postWithToast(
                                `${base}/subscription`,
                                new FormData(e.currentTarget),
                                'გეგმა მიენიჭა.',
                            );
                        }}
                    >
                        <select
                            aria-label="გეგმა"
                            name="plan_id"
                            className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                        >
                            {plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="სტატუსი"
                            name="status"
                            className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                        >
                            <option value="trial">trial</option>
                            <option value="active">active</option>
                            <option value="suspended">suspended</option>
                        </select>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={plans.length === 0}
                        >
                            მინიჭება
                        </Button>
                    </form>
                </section>

                <section className={card} aria-labelledby="support">
                    <h2 id="support" className="mb-2 text-lg">
                        მხარდაჭერის წვდომა
                    </h2>
                    <p className="mb-3 text-sm text-slate-600">
                        დროებითი, მხოლოდ სანახავი წვდომა — სკოლის
                        ადმინისტრატორის დამტკიცებით. ყველა ნახვა აღირიცხება
                        ორივე მხარეს.
                    </p>
                    {activeSupport && (
                        <Link
                            href={`${base}/support-view`}
                            className="mb-3 inline-flex min-h-11 items-center rounded-lg bg-amber-100 px-4 text-sm font-medium"
                        >
                            აქტიური სესიის გახსნა
                        </Link>
                    )}
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            postWithToast(
                                `${base}/support-requests`,
                                new FormData(e.currentTarget),
                                'მოთხოვნა გაიგზავნა სკოლაში.',
                            );
                        }}
                    >
                        <textarea
                            aria-label="მიზეზი"
                            name="reason"
                            required
                            minLength={10}
                            className="min-h-20 w-full rounded-md border border-slate-300 p-3 text-sm"
                            placeholder="რატომ გჭირდებათ წვდომა"
                        />
                        <div className="flex gap-2">
                            <Input
                                aria-label="წუთი"
                                name="minutes"
                                type="number"
                                min={15}
                                max={240}
                                defaultValue={60}
                                className="w-28"
                            />
                            <Button type="submit" size="sm">
                                მოთხოვნა
                            </Button>
                        </div>
                    </form>
                    <ul className="mt-3 space-y-1 text-sm">
                        {supportRequests.map((r) => (
                            <li key={r.id}>
                                {r.status}
                                {r.isActive && r.expiresAt
                                    ? ` · ${formatDateTime(r.expiresAt)}-მდე`
                                    : ''}{' '}
                                — {r.reason}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className={card} aria-labelledby="audit">
                    <h2 id="audit" className="mb-2 text-lg">
                        აუდიტი
                    </h2>
                    <ul className="space-y-1 text-sm">
                        {audit.map((a, i) => (
                            <li key={i}>
                                {a.createdAt ? formatDateTime(a.createdAt) : ''}{' '}
                                · {a.action}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </PlatformLayout>
    );
}
