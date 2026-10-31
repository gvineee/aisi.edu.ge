/**
 * The day card's sentence, from the server's real schedule for today
 * (ResolveDailyLessons: holidays, cancellations, substitutions and changed
 * times already applied) — never a fixed "lessons start at 09:00".
 */
type DayLesson = {
    startsAt: string;
    endsAt: string;
    subject?: string;
    cancelled: boolean;
};

/** The current wall-clock time at the school, "HH:MM". */
function schoolNow(now: Date): string {
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: 'Asia/Tbilisi',
    }).format(now);
}

export function dayPlanText(
    lessons: DayLesson[],
    dayOff: string | null | undefined,
    now: Date = new Date(),
): string {
    if (dayOff) {
        return `დღეს არასასწავლო დღეა — ${dayOff}.`;
    }

    const held = lessons
        .filter((lesson) => !lesson.cancelled)
        .sort((a, b) => a.startsAt.localeCompare(b.startsAt));

    if (held.length === 0) {
        return lessons.length > 0
            ? 'დღევანდელი გაკვეთილები გაუქმებულია.'
            : 'დღეს გაკვეთილი არ არის.';
    }

    const time = schoolNow(now);
    const about = (lesson: DayLesson) =>
        lesson.subject ? ` — ${lesson.subject}` : '';
    const current = held.find(
        (lesson) => lesson.startsAt <= time && time < lesson.endsAt,
    );
    const next = held.find((lesson) => lesson.startsAt > time);

    if (time < held[0].startsAt) {
        return `პირველი გაკვეთილი იწყება ${held[0].startsAt}-ზე${about(held[0])}.`;
    }

    if (current) {
        return `ახლა მიმდინარეობს${about(current)} (${current.endsAt}-მდე).`;
    }

    if (next) {
        return `შემდეგი გაკვეთილი ${next.startsAt}-ზე${about(next)}.`;
    }

    return 'დღევანდელი გაკვეთილები დასრულდა.';
}
