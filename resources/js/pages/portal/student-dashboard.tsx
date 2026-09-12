import { Head, Link } from '@inertiajs/react';
import { BookMarked, CalendarClock, UserX } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import ActionFeed, { type ActionItem } from '@/components/portal/action-feed';
import DashboardHeading from '@/components/portal/dashboard-heading';
import DayCard from '@/components/portal/day-card';
import StatGrid, { type Stat } from '@/components/portal/stat-grid';
import Panel from '@/components/portal/panel';
import LessonRow from '@/components/portal/lesson-row';
import NoticeCard from '@/components/portal/notice-card';

type ScheduleEntry = {
    subject: string;
    startsAt: string;
    endsAt: string;
    teacherName: string;
    roomName: string | null;
    cancelled: boolean;
};

type NewsItem = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
};

type Props = {
    linked: boolean;
    studentId?: number;
    userName: string;
    className: string | null;
    todaySchedule: ScheduleEntry[];
    actionItems: ActionItem[];
    recentNews: NewsItem[];
};

/**
 * "linked: false" is a real, honest state — this account holds an active
 * student membership but no Student record has been linked to it yet
 * (students.user_id). It is not an error to paper over with fake schedule
 * data; it means administration hasn't connected the two records.
 */
export default function StudentDashboard({
    linked,
    studentId,
    userName,
    className,
    todaySchedule,
    actionItems,
    recentNews,
}: Props) {
    const dateLabel = new Date().toLocaleDateString('ka-GE', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const firstName = userName.split(' ')[0] ?? userName;

    const stats: Stat[] = linked
        ? [
              {
                  key: 'lessons',
                  icon: CalendarClock,
                  value: String(todaySchedule.length),
                  label: 'გაკვეთილი დღეს',
                  tone: 'blue',
              },
          ]
        : [];

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <DashboardHeading
                eyebrow="ჩემი აისი / მოსწავლე"
                heading={`კარგი დღე, ${firstName}`}
                date={`${dateLabel}${className ? ` · ${className}` : ''}`}
                showSun
            />

            {!linked ? (
                <>
                    <div className="mb-6">
                        <ActionFeed items={actionItems} />
                    </div>
                    <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                        <UserX className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">
                            თქვენი ანგარიში ჯერ არ არის დაკავშირებული
                            მოსწავლის ჩანაწერთან.
                        </p>
                        <p className="mt-2 text-sm text-slate-500">
                            დაუკავშირდით სკოლის ადმინისტრაციას, რომ თქვენი
                            განრიგი გამოჩნდეს.
                        </p>
                    </div>
                </>
            ) : (
                <>
                    <div className="mb-6 grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                        <DayCard
                            eyebrow="დღის გეგმა"
                            heading="ახალი დღე, ახალი შესაძლებლობები."
                            body="გაკვეთილები იწყება 09:00-ზე. მოემზადე პირველი აღმოჩენისთვის."
                            ctaLabel="დღის განრიგი"
                            ctaHref="#todays-lessons"
                        />
                        {studentId !== undefined && (
                            <Link
                                href={`/portal/students/${studentId}/portfolio`}
                                className="flex flex-col justify-center gap-2 rounded-2xl border border-slate-200 bg-white p-6 hover:border-slate-300"
                            >
                                <BookMarked
                                    size={22}
                                    className="text-[#c84925]"
                                />
                                <span className="font-semibold">
                                    ჩემი პორტფოლიო
                                </span>
                                <span className="text-sm text-slate-500">
                                    ნამუშევრები და მასწავლებლის უკუკავშირი
                                </span>
                            </Link>
                        )}
                    </div>

                    <StatGrid stats={stats} />

                    <div className="mb-6">
                        <ActionFeed items={actionItems} />
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Panel title="დღევანდელი განრიგი">
                            <div id="todays-lessons">
                                {todaySchedule.length === 0 ? (
                                    <p className="py-6 text-center text-sm text-slate-500">
                                        დღეს გამოქვეყნებული გაკვეთილი არ
                                        არის.
                                    </p>
                                ) : (
                                    todaySchedule.map((lesson, index) => (
                                        <LessonRow
                                            key={index}
                                            index={index}
                                            startsAt={lesson.startsAt}
                                            endsAt={lesson.endsAt}
                                            title={lesson.subject}
                                            subtitle={
                                                lesson.roomName
                                                    ? `${lesson.teacherName} · ${lesson.roomName}`
                                                    : lesson.teacherName
                                            }
                                            cancelled={lesson.cancelled}
                                        />
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
                                                  ).toLocaleDateString(
                                                      'ka-GE',
                                                      {
                                                          day: 'numeric',
                                                          month: 'long',
                                                      },
                                                  )
                                                : ''
                                        }
                                        href={`/news/${post.slug}`}
                                    />
                                ))
                            )}
                        </Panel>
                    </div>
                </>
            )}
        </PortalLayout>
    );
}
