import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, X } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { firstError } from '@/lib/assessment';

type Props = {
    capabilities: { value: string; label: string }[];
    staff: {
        id: number;
        name: string;
        hasMfa: boolean;
        grants: { id: number; capability: string }[];
    }[];
};

/**
 * Director/admin grant narrowly-scoped health capabilities to individual
 * staff members. Granting is audit-logged; holding a grant without 2FA
 * still does not open the full record.
 */
export default function HealthPermissions({ capabilities, staff }: Props) {
    const labelOf = (value: string) =>
        capabilities.find((c) => c.value === value)?.label ?? value;

    const options = {
        preserveScroll: true,
        onError: (e: Record<string, string>) =>
            toast.error(firstError(e) ?? 'ვერ შესრულდა.'),
    };

    return (
        <PortalLayout>
            <Head title="ჯანმრთელობის წვდომის უფლებები" />
            <Link
                href="/portal/health"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> ჯანმრთელობა
            </Link>
            <h1 className="mb-2 text-2xl">ჯანმრთელობის წვდომის უფლებები</h1>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                ადმინისტრატორისა და დირექტორის როლი ჯანმრთელობის ჩანაწერს
                ავტომატურად არ ხსნის. უფლება ეძლევა კონკრეტულ ადამიანს,
                აღირიცხება და მოქმედებს მხოლოდ ჩართული ორფაქტორიანი
                ავთენტიფიკაციით.
            </p>

            <ul className="space-y-3">
                {staff.map((member) => {
                    const held = member.grants.map((g) => g.capability);
                    const available = capabilities.filter(
                        (c) => !held.includes(c.value),
                    );

                    return (
                        <li
                            key={member.id}
                            className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                        >
                            <p className="font-medium">
                                {member.name}
                                {!member.hasMfa && (
                                    <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                                        2FA არ არის ჩართული
                                    </span>
                                )}
                            </p>
                            <ul className="mt-2 flex flex-wrap gap-2">
                                {member.grants.map((grant) => (
                                    <li
                                        key={grant.id}
                                        className="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pr-1 pl-3 text-sm"
                                    >
                                        {labelOf(grant.capability)}
                                        <button
                                            type="button"
                                            aria-label={`უფლების გაუქმება: ${labelOf(grant.capability)}`}
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-full hover:bg-slate-200"
                                            onClick={() =>
                                                router.post(
                                                    `/portal/health/permissions/${grant.id}/revoke`,
                                                    {},
                                                    {
                                                        ...options,
                                                        onSuccess: () =>
                                                            toast.success(
                                                                'უფლება გაუქმდა.',
                                                            ),
                                                    },
                                                )
                                            }
                                        >
                                            <X size={14} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            {available.length > 0 && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {available.map((c) => (
                                        <Button
                                            key={c.value}
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    '/portal/health/permissions',
                                                    {
                                                        user_id: member.id,
                                                        capability: c.value,
                                                    },
                                                    {
                                                        ...options,
                                                        onSuccess: () =>
                                                            toast.success(
                                                                'უფლება მიენიჭა.',
                                                            ),
                                                    },
                                                )
                                            }
                                        >
                                            + {c.label}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </PortalLayout>
    );
}
