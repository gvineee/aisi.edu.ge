import { Head } from '@inertiajs/react';
import PortalLayout from '@/layouts/portal/portal-layout';

type Props = {
    name: string;
};

/**
 * Real state for an authenticated user with no active role in this
 * tenant yet — not a placeholder dashboard with invented content.
 */
export default function NoRole({ name }: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                <h1 className="mb-3 text-xl">გამარჯობა, {name}</h1>
                <p className="text-slate-600">
                    თქვენს ანგარიშს ჯერ არცერთი როლი არ აქვს მინიჭებული ამ
                    სკოლაში. დაუკავშირდით ადმინისტრაციას.
                </p>
            </div>
        </PortalLayout>
    );
}
