import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { BookMarked, CalendarClock, Users } from 'lucide-react';
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

type Child = {
    id: number;
    name: string;
    className: string | null;
    permissions: {
        academic: boolean;
        financial: boolean;
        pickup: boolean;
        notifications: boolean;
    };
    todaySchedule: ScheduleEntry[];
};

type NewsItem = {
    slug: string;
    title: string;
    excerpt: string | null;
    publishedAt: string | null;
};

type Props = {
    children: Child[];
    actionItems: ActionItem[];
    recentNews: NewsItem[];
};

/**
 * Every value here comes from the authenticated guardian's own active
 * guardian_links (DashboardController) — never a client-supplied child id,
 * and never demo data. An empty list is a real, honest state, not
 * something papered over with placeholders.
 */
export default function ParentDashboard({
    children,
    actionItems,
    recentNews,
}: Props) {
    const [selectedId, setSelectedId] = useState(children[0]?.id ?? null);
    const selected = children.find((c) => c.id === selectedId) ?? null;

    const dateLabel = new Date().toLocaleDateString('ka-GE', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const stats: Stat[] =
        selected && selected.permissions.academic
            ? [
                  {
                      key: 'lessons',
                      icon: CalendarClock,
                      value: String(selected.todaySchedule.length),
                      label: 'გაკვეთილი დღეს',
                      tone: 'blue',
                  },
              ]
            : [];

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <DashboardHeading
                eyebrow="ჩემი აისი / მშობელი"
                heading="ახალი დღე, ახალი შესაძლებლობები."
                date={dateLabel}
                showSun
            />

            {children.length === 0 ? (
                <>
                    <div className="mb-6">
                        <ActionFeed items={actionItems} />
                    </div>
                    <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                        <Users className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">
                            თქვენს ანგარიშზე ჯერ არცერთი ბავშვი არ არის
                            დაკავშირებული.
                        </p>
                        <p className="mt-2 text-sm text-slate-500">
                            დაუკავშირდით სკოლის ადმინისტრაციას, თუ ეს
                            მოსალოდნელი არ იყო.
                        </p>
                    </div>
                </>
            ) : (
                <>
                    {children.length > 1 && (
                        <div
                            className="mb-6 flex gap-2"
                            role="tablist"
                            aria-label="ბავშვის არჩევა"
                        >
                            {children.map((child) => (
                                <button
                                    key={child.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={selectedId === child.id}
                                    onClick={() => setSelectedId(child.id)}
                                    className={`min-h-11 rounded-full border px-4 py-2 text-sm font-medium ${
                                        selectedId === child.id
                                            ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-white'
                                            : 'border-slate-300 bg-white'
                                    }`}
                                >
                                    {child.name}
                                </button>
                            ))}
                        </div>
                    )}

                    {selected && (
                        <>
                            <div className="mb-6 grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                                <DayCard
                                    eyebrow={`${selected.name}-ის დღე`}
                                    heading="ახალი დღე, ახალი შესაძლებლობები."
                                    body="გაკვეთილები იწყება 09:00-ზე. მოემზადე პირველი აღმოჩენისთვის."
                                    ctaLabel="დღის განრიგი"
                                    ctaHref="#todays-lessons"
                                />
                                {selected.permissions.academic && (
                                    <Link
                                        href={`/portal/students/${selected.id}/portfolio`}
                                        className="flex flex-col justify-center gap-2 rounded-2xl border border-slate-200 bg-white p-6 hover:border-slate-300"
                                    >
                                        <BookMarked
                                            size={22}
                                            className="text-[#c84925]"
                                        />
                                        <span className="font-semibold">
                                            {selected.name}-ის პორტფოლიო
                                        </span>
                                        <span className="text-sm text-slate-500">
                                            ნამუშევრები და უკუკავშირი
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
                                        {!selected.permissions.academic ? (
                                            <p className="py-6 text-center text-sm text-slate-500">
                                                აკადემიური ინფორმაციის ნახვის
                                                უფლება არ გაქვთ ამ
                                                ბავშვისთვის.
                                            </p>
                                        ) : selected.todaySchedule.length ===
                                          0 ? (
                                            <p className="py-6 text-center text-sm text-slate-500">
                                                დღეს გამოქვეყნებული
                                                გაკვეთილი არ არის.
                                            </p>
                                        ) : (
                                            selected.todaySchedule.map(
                                                (lesson, index) => (
                                                    <LessonRow
                                                        key={index}
                                                        index={index}
                                                        startsAt={
                                                            lesson.startsAt
                                                        }
                                                        endsAt={lesson.endsAt}
                                                        title={lesson.subject}
                                                        subtitle={
                                                            lesson.roomName
                                                                ? `${lesson.teacherName} · ${lesson.roomName}`
                                                                : lesson.teacherName
                                                        }
                                                        cancelled={
                                                            lesson.cancelled
                                                        }
                                                    />
                                                ),
                                            )
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
                </>
            )}
        </PortalLayout>
    );
}
