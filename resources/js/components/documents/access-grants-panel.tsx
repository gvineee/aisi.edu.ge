import { router } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';
import { ShieldCheck, X } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { firstError } from '@/lib/assessment';
import {
    permissionHints,
    permissionLabels,
    formText,
    selectClass,
} from '@/components/documents/labels';

export type AccessGrant = {
    id: number;
    userId: number | null;
    userName: string | null;
    role: string | null;
    roleLabel: string | null;
    permission: string;
};

type Props = {
    resourceType: 'workspace' | 'document';
    resourceId: number;
    grants: AccessGrant[];
    staff: { id: number; name: string }[];
    roleOptions: Record<string, string>;
    title?: string;
    description?: string;
};

/**
 * Who has extra access to one workspace or document. Grants target either a
 * single staff member or everyone in a staff role; the server re-checks
 * that the viewer manages this resource on every change.
 */
export default function AccessGrantsPanel({
    resourceType,
    resourceId,
    grants,
    staff,
    roleOptions,
    title = 'წვდომა',
    description,
}: Props) {
    const id = useId();
    const [target, setTarget] = useState<'role' | 'user'>('role');
    const [busy, setBusy] = useState(false);

    const add = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const value = formText(form, 'target_value', '');

        if (value === '') {
            toast.error(
                target === 'role' ? 'აირჩიეთ როლი.' : 'აირჩიეთ თანამშრომელი.',
            );
            return;
        }

        setBusy(true);
        router.post(
            '/documents/access-grants',
            {
                resource_type: resourceType,
                resource_id: resourceId,
                permission: formText(form, 'permission'),
                ...(target === 'role' ? { role: value } : { user_id: value }),
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('წვდომა მიენიჭა.'),
                onError: (errors) =>
                    toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    const revoke = (grant: AccessGrant) => {
        router.delete(`/documents/access-grants/${grant.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('წვდომა გაუქმდა.'),
            onError: () => toast.error('ვერ შესრულდა.'),
        });
    };

    return (
        <section aria-labelledby={`${id}-title`} className="space-y-3">
            <div>
                <h3
                    id={`${id}-title`}
                    className="flex items-center gap-2 text-base font-medium"
                >
                    <ShieldCheck size={18} aria-hidden /> {title}
                </h3>
                {description && (
                    <p className="text-sm text-slate-500">{description}</p>
                )}
            </div>

            {grants.length === 0 ? (
                <p className="text-sm text-slate-500">
                    დამატებითი წვდომა არავის აქვს.
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    {grants.map((grant) => (
                        <li
                            key={grant.id}
                            className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <span className="min-w-0">
                                <strong className="break-words">
                                    {grant.userName ??
                                        grant.roleLabel ??
                                        grant.role}
                                </strong>{' '}
                                <span className="text-slate-500">
                                    ·{' '}
                                    {permissionLabels[grant.permission] ??
                                        grant.permission}
                                </span>
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="min-h-11 min-w-11"
                                onClick={() => revoke(grant)}
                                aria-label={`წვდომის გაუქმება: ${grant.userName ?? grant.roleLabel ?? ''}`}
                            >
                                <X size={16} aria-hidden />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <form
                onSubmit={add}
                className="grid gap-2 sm:grid-cols-[auto_1fr_auto_auto] sm:items-end"
            >
                <fieldset className="flex gap-3 text-sm">
                    <legend className="sr-only">ვის ეძლევა წვდომა</legend>
                    <label className="flex min-h-11 items-center gap-1">
                        <input
                            type="radio"
                            name="target"
                            checked={target === 'role'}
                            onChange={() => setTarget('role')}
                        />
                        როლს
                    </label>
                    <label className="flex min-h-11 items-center gap-1">
                        <input
                            type="radio"
                            name="target"
                            checked={target === 'user'}
                            onChange={() => setTarget('user')}
                        />
                        პირს
                    </label>
                </fieldset>
                <div>
                    <label htmlFor={`${id}-value`} className="sr-only">
                        {target === 'role' ? 'როლი' : 'თანამშრომელი'}
                    </label>
                    <select
                        id={`${id}-value`}
                        name="target_value"
                        key={target}
                        defaultValue=""
                        className={selectClass}
                    >
                        <option value="">
                            {target === 'role'
                                ? 'აირჩიეთ როლი'
                                : 'აირჩიეთ თანამშრომელი'}
                        </option>
                        {target === 'role'
                            ? Object.entries(roleOptions).map(
                                  ([value, label]) => (
                                      <option key={value} value={value}>
                                          {label}
                                      </option>
                                  ),
                              )
                            : staff.map((person) => (
                                  <option key={person.id} value={person.id}>
                                      {person.name}
                                  </option>
                              ))}
                    </select>
                </div>
                <div>
                    <label htmlFor={`${id}-permission`} className="sr-only">
                        დონე
                    </label>
                    <select
                        id={`${id}-permission`}
                        name="permission"
                        defaultValue="view"
                        className={selectClass}
                    >
                        {Object.entries(permissionLabels).map(
                            ([value, label]) => (
                                <option
                                    key={value}
                                    value={value}
                                    title={permissionHints[value]}
                                >
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                </div>
                <Button type="submit" disabled={busy} className="min-h-11">
                    მინიჭება
                </Button>
            </form>
            <p className="text-xs text-slate-500">
                ნახვა — {permissionHints.view}; რედაქტირება —{' '}
                {permissionHints.edit}; მართვა — {permissionHints.manage}.
            </p>
        </section>
    );
}
