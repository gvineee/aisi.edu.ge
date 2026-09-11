import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/enrollment-verification';

type Props = {
    name: string;
};

/**
 * Real state for an authenticated user with no active role in this
 * tenant yet — not a placeholder dashboard with invented content. Offers
 * the self-service verification path (verify-enrollment.tsx itself shows
 * pending/approved/rejected state once a request exists) rather than only
 * telling the user to contact the school by some other channel.
 */
export default function NoRole({ name }: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                <h1 className="mb-3 text-xl">გამარჯობა, {name}</h1>
                <p className="mb-6 text-slate-600">
                    თქვენს ანგარიშს ჯერ არცერთი როლი არ აქვს მინიჭებული ამ
                    სკოლაში. თუ თქვენ ხართ მოსწავლე ან მშობელი, დაადასტურეთ
                    ვინაობა — ადმინისტრაცია შეამოწმებს და დაგიმატებთ.
                </p>
                <Button asChild>
                    <Link href={show().url}>ვინაობის დადასტურება</Link>
                </Button>
            </div>
        </PortalLayout>
    );
}
