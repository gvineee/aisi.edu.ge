import { Head, Link } from '@inertiajs/react';
import { FileText, Palette, Users } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';

type Props = {
    memberCount: number;
    pendingApprovals: number;
    actionItems: ActionItem[];
};

/**
 * Real numbers where real numbers exist (member count, pending approvals);
 * an honest "მალე" card for members/CMS/brand management screens that
 * don't exist yet, rather than a broken link (CLAUDE.md: never fill an
 * empty dashboard with fabricated data).
 */
export default function AdminDashboard({
    memberCount,
    pendingApprovals,
    actionItems,
}: Props) {
    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <h1 className="mb-2 text-2xl">დღეს</h1>
            <p className="mb-8 text-sm text-slate-500">
                {new Date().toLocaleDateString('ka-GE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                })}
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
                <section className="rounded-xl border border-slate-200 bg-white p-6">
                    <span className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100">
                        <Users size={20} className="text-slate-600" />
                    </span>
                    <p className="text-2xl font-semibold">{memberCount}</p>
                    <p className="text-sm text-slate-500">
                        აქტიური წევრი ამ სკოლაში
                    </p>
                </section>

                <Link
                    href="/documents/director-worklist"
                    className="rounded-xl border border-slate-200 bg-white p-6 hover:border-slate-300"
                >
                    <span className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100">
                        <FileText size={20} className="text-slate-600" />
                    </span>
                    <p className="text-2xl font-semibold">{pendingApprovals}</p>
                    <p className="text-sm text-slate-500">
                        დასამტკიცებელი დოკუმენტი
                    </p>
                </Link>
            </div>

            <div className="mt-6">
                <ActionFeed items={actionItems} />
            </div>

            <section className="mt-6 rounded-xl border border-dashed border-slate-300 p-8 text-center">
                <Palette className="mx-auto mb-3 h-8 w-8 text-slate-400" />
                <p className="font-medium">
                    წევრების მართვა, მოწვევები, CMS და ბრენდის პარამეტრები მალე
                    იქნება ხელმისაწვდომი.
                </p>
                <p className="mt-2 text-sm text-slate-500">
                    ეს ეკრანები ჯერ არ არის აშენებული — არაფერი აქ არ არის
                    გამოგონილი მონაცემი.
                </p>
            </section>
        </PortalLayout>
    );
}
