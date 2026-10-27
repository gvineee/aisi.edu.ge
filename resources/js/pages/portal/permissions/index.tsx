import { Head, Link } from '@inertiajs/react';
import { Check, ShieldCheck } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';

type Props = {
    roles: { value: string; label: string; members: number; mfa: boolean }[];
    rows: {
        key: string;
        label: string;
        groupLabel: string | null;
        roles: Record<string, boolean>;
        scope: string | null;
    }[];
};

/**
 * Which role reaches which module — built from the portal navigation
 * itself. Who holds which role changes on the members page.
 */
export default function PermissionsIndex({ roles, rows }: Props) {
    let lastGroup: string | null | undefined;

    return (
        <PortalLayout>
            <Head title="როლები და უფლებები" />
            <div className="mb-2 flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-2xl">როლები და უფლებები</h1>
                <Button asChild variant="outline">
                    <Link href="/portal/members">როლის მინიჭება — წევრები</Link>
                </Button>
            </div>
            <p className="mb-6 max-w-3xl text-sm text-slate-600">
                ცხრილი აჩვენებს, რომელ განყოფილებაზე აქვს წვდომა თითოეულ როლს.
                თითოეული როლის უფლებები სისტემაშია განსაზღვრული და შემოწმებულია:
                სერვერი წვდომას ყოველ მოთხოვნაზე ამოწმებს. ვის რომელი როლი აქვს,
                ამას „წევრებში“ ცვლით. ერთ ადამიანს შეიძლება რამდენიმე როლი
                ჰქონდეს.
            </p>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full min-w-[900px] text-sm">
                    <caption className="sr-only">
                        განყოფილებები და როლები
                    </caption>
                    <thead className="bg-slate-50 text-xs text-slate-600">
                        <tr>
                            <th
                                scope="col"
                                className="sticky left-0 bg-slate-50 px-4 py-3 text-left font-medium"
                            >
                                განყოფილება
                            </th>
                            {roles.map((role) => (
                                <th
                                    key={role.value}
                                    scope="col"
                                    className="px-2 py-3 text-center font-medium"
                                >
                                    <span className="block">{role.label}</span>
                                    <span className="block font-normal text-slate-400">
                                        {role.members} წევრი
                                    </span>
                                    {role.mfa && (
                                        <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-900">
                                            <ShieldCheck
                                                size={10}
                                                aria-hidden
                                            />{' '}
                                            2FA
                                        </span>
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const heading =
                                row.groupLabel !== lastGroup &&
                                row.groupLabel !== null;
                            lastGroup = row.groupLabel;

                            return [
                                heading && (
                                    <tr
                                        key={`group-${row.key}`}
                                        className="border-t border-slate-200 bg-slate-50/60"
                                    >
                                        <th
                                            colSpan={roles.length + 1}
                                            scope="colgroup"
                                            className="px-4 py-2 text-left text-xs font-medium text-slate-500"
                                        >
                                            {row.groupLabel}
                                        </th>
                                    </tr>
                                ),
                                <tr
                                    key={row.key}
                                    className="border-t border-slate-100"
                                >
                                    <th
                                        scope="row"
                                        className="sticky left-0 bg-white px-4 py-2.5 text-left font-medium"
                                    >
                                        {row.label}
                                        {row.scope && (
                                            <span className="mt-0.5 block max-w-xs text-xs font-normal text-slate-500">
                                                {row.scope}
                                            </span>
                                        )}
                                    </th>
                                    {roles.map((role) => (
                                        <td
                                            key={role.value}
                                            className="px-2 py-2.5 text-center"
                                        >
                                            {row.roles[role.value] ? (
                                                <Check
                                                    className="mx-auto h-4 w-4 text-emerald-600"
                                                    aria-label="აქვს წვდომა"
                                                />
                                            ) : (
                                                <span
                                                    className="text-slate-300"
                                                    aria-label="წვდომა არ აქვს"
                                                >
                                                    ·
                                                </span>
                                            )}
                                        </td>
                                    ))}
                                </tr>,
                            ];
                        })}
                    </tbody>
                </table>
            </div>
            <p className="mt-4 max-w-3xl text-xs text-slate-500">
                „2FA“ — ამ როლისთვის ორფაქტორიანი ავთენტიფიკაცია სავალდებულოა.
                ჩართული მოდულები (ფინანსები, ბიბლიოთეკა, სერვისები) ჩანს მხოლოდ
                მაშინ, როცა სკოლისთვის ჩართულია.
            </p>
        </PortalLayout>
    );
}
