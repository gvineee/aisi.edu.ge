import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck, FileText, Users } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';
import DashboardHeading from '@/components/portal/dashboard-heading';
import DayCard from '@/components/portal/day-card';
import StatGrid, { type Stat } from '@/components/portal/stat-grid';
import Panel from '@/components/portal/panel';
import NoticeCard from '@/components/portal/notice-card';

type PreviewItem = {
    id: number;
    documentId: number;
    documentTitle: string;
    authorName: string;
    submittedAt: string | null;
};

type NewsItem = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
};

type Props = {
    pendingCount: number;
    preview: PreviewItem[];
    actionItems: ActionItem[];
    recentNews: NewsItem[];
};

export default function DirectorDashboard({
    pendingCount,
    preview,
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
            key: 'approvals',
            icon: FileText,
            value: String(pendingCount),
            label: 'დასამტკიცებელი დოკუმენტი',
            tone: 'peach',
        },
    ];

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <DashboardHeading
                eyebrow="ჩემი აისი / დირექტორი"
                heading="ყველაფერი იწყება კარგი დღით."
                date={dateLabel}
                showSun
            />

            <div className="mb-6 grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <DayCard
                    eyebrow="დღის შეჯამება"
                    heading="ყველაფერი იწყება კარგი დღით."
                    body="განრიგი, დასწრება და მიმდინარე მოთხოვნები ერთ სივრცეში."
                    ctaLabel="სამუშაო სიის ნახვა"
                    ctaHref="/documents/director-worklist"
                />
                <Link
                    href="/portal/members"
                    className="flex flex-col justify-center gap-2 rounded-2xl border border-slate-200 bg-white p-6 hover:border-slate-300"
                >
                    <Users size={22} className="text-[#c84925]" />
                    <span className="font-semibold">წევრების მართვა</span>
                    <span className="text-sm text-slate-500">
                        მოწვევები და ვინაობის დამტკიცება
                    </span>
                </Link>
            </div>

            <StatGrid stats={stats} />

            <div className="mb-6">
                <ActionFeed items={actionItems} />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel
                    title="ბოლოდროინდელი მოთხოვნები"
                    action={
                        preview.length > 0
                            ? {
                                  label: 'სრულად',
                                  href: '/documents/director-worklist',
                              }
                            : undefined
                    }
                >
                    {preview.length === 0 ? (
                        <div className="py-6 text-center">
                            <CheckCircle2 className="mx-auto mb-3 h-8 w-8 text-slate-400" />
                            <p className="text-sm text-slate-500">
                                ამჟამად დასამტკიცებელი დოკუმენტი არ არის.
                            </p>
                        </div>
                    ) : (
                        preview.map((item) => (
                            <Link
                                key={item.id}
                                href={`/documents/${item.documentId}`}
                                className="flex items-center justify-between gap-3 border-b border-slate-100 py-3 text-sm last:border-0"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-medium">
                                        {item.documentTitle}
                                    </p>
                                    <p className="text-slate-500">
                                        {item.authorName}
                                    </p>
                                </div>
                                <ClipboardCheck
                                    size={16}
                                    className="shrink-0 text-slate-400"
                                />
                            </Link>
                        ))
                    )}
                </Panel>

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
            </div>
        </PortalLayout>
    );
}
