import { Head, Link } from '@inertiajs/react';
import { BookMarked, CalendarClock, ClipboardList, Repeat } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';
import DashboardHeading from '@/components/portal/dashboard-heading';
import DayCard from '@/components/portal/day-card';
import StatGrid, { type Stat } from '@/components/portal/stat-grid';
import Panel from '@/components/portal/panel';
import LessonRow from '@/components/portal/lesson-row';
import NoticeCard from '@/components/portal/notice-card';

type LessonEntry = {
    lessonId: number;
    subject: string;
    className: string;
    startsAt: string;
    endsAt: string;
    roomName: string | null;
    cancelled: boolean;
};

type NewsItem = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
};

type SubstitutionEntry = {
    lessonId: number;
    subject: string;
    className: string;
    startsAt: string;
    endsAt: string;
    roomName: string | null;
    absentTeacherName: string;
};

type Props = {
    date: string;
    lessons: LessonEntry[];
    portfolioReviewCount: number;
    substitutions: SubstitutionEntry[];
    actionItems: ActionItem[];
    recentNews: NewsItem[];
};

export default function TeacherDashboard({
    date,
    lessons,
    portfolioReviewCount,
    substitutions,
    actionItems,
    recentNews,
}: Props) {
    const dateLabel = new Date(date).toLocaleDateString('ka-GE', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const stats: Stat[] = [
        {
            key: 'lessons',
            icon: CalendarClock,
            value: String(lessons.length),
            label: 'გაკვეთილი დღეს',
            tone: 'blue',
        },
        ...(portfolioReviewCount > 0
            ? [
                  {
                      key: 'portfolio',
                      icon: BookMarked,
                      value: String(portfolioReviewCount),
                      label: 'პორტფოლიო განსახილველად',
                      tone: 'peach' as const,
                  },
              ]
            : []),
    ];

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <DashboardHeading
                eyebrow="ჩემი აისი / მასწავლებელი"
                heading="ახალი დღე, ახალი შესაძლებლობები."
                date={dateLabel}
                showSun
            />

            <div className="mb-6 grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <DayCard
                    eyebrow="დღის გეგმა"
                    heading="ახალი დღე, ახალი შესაძლებლობები."
                    body="გაკვეთილები იწყება 09:00-ზე. მოემზადე პირველი აღმოჩენისთვის."
                    ctaLabel="დღის განრიგი"
                    ctaHref="#todays-lessons"
                />
                <Link
                    href="/portal/portfolio/review-queue"
                    className="flex flex-col justify-center gap-2 rounded-2xl border border-slate-200 bg-white p-6 hover:border-slate-300"
                >
                    <BookMarked size={22} className="text-[#c84925]" />
                    <span className="font-semibold">
                        პორტფოლიოს განხილვა
                    </span>
                    <span className="text-sm text-slate-500">
                        მოსწავლეების გაგზავნილი ნამუშევრები
                    </span>
                </Link>
            </div>

            <StatGrid stats={stats} />

            <div className="mb-6">
                <ActionFeed items={actionItems} />
            </div>

            {substitutions.length > 0 && (
                <div className="mb-6 space-y-3">
                    {substitutions.map((substitution) => (
                        <div
                            key={substitution.lessonId}
                            className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4"
                        >
                            <Repeat
                                size={20}
                                className="mt-0.5 shrink-0 text-amber-700"
                            />
                            <p className="text-sm text-amber-900">
                                დღეს ჩაანაცვლებთ{' '}
                                <strong>
                                    {substitution.absentTeacherName}
                                </strong>
                                -ს — {substitution.subject} ·{' '}
                                {substitution.className} (
                                {substitution.startsAt}–
                                {substitution.endsAt}
                                {substitution.roomName
                                    ? `, ${substitution.roomName}`
                                    : ''}
                                )
                            </p>
                        </div>
                    ))}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel title="დღევანდელი გაკვეთილები" className="lg:col-span-1">
                    <div id="todays-lessons">
                        {lessons.length === 0 ? (
                            <div className="py-6 text-center">
                                <CalendarClock className="mx-auto mb-3 h-8 w-8 text-slate-400" />
                                <p className="text-sm text-slate-500">
                                    დღეს გამოქვეყნებული გაკვეთილი არ გაქვთ.
                                </p>
                            </div>
                        ) : (
                            lessons.map((lesson, index) => (
                                <div
                                    key={lesson.lessonId}
                                    className="group relative"
                                >
                                    <LessonRow
                                        index={index}
                                        startsAt={lesson.startsAt}
                                        endsAt={lesson.endsAt}
                                        title={`${lesson.subject} · ${lesson.className}`}
                                        subtitle={lesson.roomName}
                                        cancelled={lesson.cancelled}
                                    />
                                    {!lesson.cancelled && (
                                        <Link
                                            href={`/lessons/${lesson.lessonId}/attendance?date=${date}`}
                                            className="absolute top-4 right-0 inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium hover:bg-slate-200"
                                        >
                                            <ClipboardList size={14} />{' '}
                                            დასწრება
                                        </Link>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
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
