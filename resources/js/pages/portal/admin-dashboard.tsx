import { Head, Link } from '@inertiajs/react';
import { FileText, Newspaper, Users } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';
import DashboardHeading from '@/components/portal/dashboard-heading';
import DayCard from '@/components/portal/day-card';
import StatGrid, { type Stat } from '@/components/portal/stat-grid';
import Panel from '@/components/portal/panel';
import NoticeCard from '@/components/portal/notice-card';

type NewsItem = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
};

type Props = {
    memberCount: number;
    pendingApprovals: number;
    actionItems: ActionItem[];
    recentNews: NewsItem[];
};

/**
 * Real numbers only (member count, pending approvals) — no fabricated
 * "მალე" placeholder for members/CMS anymore, since both are real, built
 * screens now (App\Http\Controllers\Portal\MemberController,
 * CmsPageController). Brand/white-label settings remain genuinely
 * unbuilt (CLAUDE-PLATFORM-MODULES.md's later phases).
 */
export default function AdminDashboard({
    memberCount,
    pendingApprovals,
    actionItems,
    recentNews,
}: Props) {
    const dateLabel = new Date().toLocaleDateString('ka-GE', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const stats: Stat[] = [
        {
            key: 'members',
            icon: Users,
            value: String(memberCount),
            label: 'აქტიური წევრი ამ სკოლაში',
            tone: 'blue',
        },
        {
            key: 'approvals',
            icon: FileText,
            value: String(pendingApprovals),
            label: 'დასამტკიცებელი დოკუმენტი',
            tone: 'peach',
        },
    ];

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <DashboardHeading
                eyebrow="ჩემი აისი / ადმინისტრატორი"
                heading="ყველაფერი იწყება კარგი დღით."
                date={dateLabel}
                showSun
            />

            <div className="mb-6 grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <DayCard
                    eyebrow="დღის შეჯამება"
                    heading="ყველაფერი იწყება კარგი დღით."
                    body="წევრები, დოკუმენტები და მიმდინარე მოთხოვნები ერთ სივრცეში."
                    ctaLabel="წევრების მართვა"
                    ctaHref="/portal/members"
                />
                <Link
                    href="/portal/cms/pages"
                    className="flex flex-col justify-center gap-2 rounded-2xl border border-slate-200 bg-white p-6 hover:border-slate-300"
                >
                    <Newspaper size={22} className="text-[#c84925]" />
                    <span className="font-semibold">
                        CMS — გვერდები და ამბები
                    </span>
                    <span className="text-sm text-slate-500">
                        მონახაზი, გადახედვა, გამოქვეყნება, მედია
                    </span>
                </Link>
            </div>

            <StatGrid stats={stats} />

            <div className="mb-6">
                <ActionFeed items={actionItems} />
            </div>

            <Panel title="სკოლის ამბები">
                {recentNews.length === 0 ? (
                    <p className="py-6 text-center text-sm text-slate-500">
                        სიახლეები მალე გამოქვეყნდება.
                    </p>
                ) : (
                    recentNews.map((post) => (
                        <NoticeCard
                            key={post.slug}
                            tag="სიახლე"
                            title={post.title}
                            excerpt={post.excerpt}
                            dateLabel={
                                post.publishedAt
                                    ? new Date(
                                          post.publishedAt,
                                      ).toLocaleDateString('ka-GE', {
                                          day: 'numeric',
                                          month: 'long',
                                      })
                                    : ''
                            }
                            href={`/news/${post.slug}`}
                        />
                    ))
                )}
            </Panel>
        </PortalLayout>
    );
}
