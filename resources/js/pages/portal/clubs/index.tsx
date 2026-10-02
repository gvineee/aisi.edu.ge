import { Head, Link } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Plus, Star } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { WEEKDAYS, formatMoney, postWithToast } from '@/lib/operations';

type ClubRow = {
    id: number;
    name: string;
    description: string | null;
    leaderName: string | null;
    capacity: number;
    enrolled: number;
    feeMinor: number | null;
    currency: string;
    isOpen: boolean;
    canSeeRoster: boolean;
    sessions: {
        dayOfWeek: number;
        startsAt: string;
        endsAt: string;
        location: string | null;
    }[];
    children: {
        id: number;
        name: string;
        status: 'enrolled' | 'waitlisted' | 'none';
        waitPosition: number | null;
    }[];
};

type Props = {
    isManagement: boolean;
    clubs: ClubRow[];
    teachers: { id: number; name: string }[];
};

type SessionDraft = {
    day_of_week: string;
    starts_at: string;
    ends_at: string;
    location: string;
};

/**
 * Clubs: places are limited; when full, families join an ordered waitlist
 * and the first in line is moved in automatically when a place frees up.
 */
export default function ClubsIndex({ isManagement, clubs, teachers }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [sessions, setSessions] = useState<SessionDraft[]>([
        {
            day_of_week: '2',
            starts_at: '15:00',
            ends_at: '16:00',
            location: '',
        },
    ]);

    const create = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        postWithToast(
            '/portal/clubs',
            {
                name: form.get('name'),
                description: form.get('description'),
                capacity: form.get('capacity'),
                fee: form.get('fee') || undefined,
                leader_id: form.get('leader_id') || undefined,
                sessions,
            },
            'კლუბი დაემატა.',
            () => setShowForm(false),
        );
    };

    return (
        <PortalLayout>
            <Head title="კლუბები" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">კლუბები</h1>
                {isManagement && (
                    <Button
                        variant="outline"
                        onClick={() => setShowForm((v) => !v)}
                        aria-expanded={showForm}
                    >
                        <Plus size={16} /> ახალი კლუბი
                    </Button>
                )}
            </div>

            {showForm && (
                <form
                    onSubmit={create}
                    className="mb-6 grid gap-4 rounded-xl bg-white p-6 ring-1 ring-slate-200 sm:grid-cols-2"
                >
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="club-name"
                            className="mb-1 block text-sm font-medium"
                        >
                            დასახელება
                        </label>
                        <Input
                            id="club-name"
                            name="name"
                            required
                            maxLength={255}
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="club-desc"
                            className="mb-1 block text-sm font-medium"
                        >
                            აღწერა
                        </label>
                        <textarea
                            id="club-desc"
                            name="description"
                            maxLength={2000}
                            className="min-h-20 w-full rounded-md border border-slate-300 p-3 text-sm"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="club-capacity"
                            className="mb-1 block text-sm font-medium"
                        >
                            ადგილები
                        </label>
                        <Input
                            id="club-capacity"
                            name="capacity"
                            type="number"
                            min={1}
                            max={500}
                            required
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="club-fee"
                            className="mb-1 block text-sm font-medium"
                        >
                            საფასური, ₾ (ცარიელი = უფასო)
                        </label>
                        <Input
                            id="club-fee"
                            name="fee"
                            type="number"
                            min={0}
                            step="0.01"
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="club-leader"
                            className="mb-1 block text-sm font-medium"
                        >
                            ხელმძღვანელი
                        </label>
                        <select
                            id="club-leader"
                            name="leader_id"
                            className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                        >
                            <option value="">—</option>
                            {teachers.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <fieldset className="space-y-2 sm:col-span-2">
                        <legend className="text-sm font-medium">განრიგი</legend>
                        {sessions.map((s, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-2 gap-2 sm:grid-cols-4"
                            >
                                <select
                                    aria-label="დღე"
                                    value={s.day_of_week}
                                    onChange={(e) =>
                                        setSessions((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          day_of_week:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                    className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                                >
                                    {Object.entries(WEEKDAYS).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <Input
                                    aria-label="დაწყება"
                                    type="time"
                                    value={s.starts_at}
                                    onChange={(e) =>
                                        setSessions((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          starts_at:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                <Input
                                    aria-label="დასრულება"
                                    type="time"
                                    value={s.ends_at}
                                    onChange={(e) =>
                                        setSessions((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          ends_at:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                <Input
                                    aria-label="ადგილი"
                                    placeholder="ადგილი"
                                    value={s.location}
                                    onChange={(e) =>
                                        setSessions((all) =>
                                            all.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          location:
                                                              e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                            </div>
                        ))}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setSessions((all) => [
                                    ...all,
                                    {
                                        day_of_week: '4',
                                        starts_at: '15:00',
                                        ends_at: '16:00',
                                        location: '',
                                    },
                                ])
                            }
                        >
                            + დრო
                        </Button>
                    </fieldset>
                    <Button type="submit" className="sm:col-span-2 sm:w-fit">
                        შენახვა
                    </Button>
                </form>
            )}

            {clubs.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <Star className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                    <p className="font-medium">კლუბი ჯერ არ დამატებულა.</p>
                </div>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2">
                    {clubs.map((club) => {
                        const percent = Math.min(
                            100,
                            Math.round((club.enrolled / club.capacity) * 100),
                        );
                        const full = club.enrolled >= club.capacity;

                        return (
                            <li
                                key={club.id}
                                className="flex flex-col gap-3 rounded-2xl bg-white p-5 ring-1 ring-slate-200"
                            >
                                <div>
                                    <h2 className="text-lg font-semibold">
                                        {club.name}
                                    </h2>
                                    {club.leaderName && (
                                        <p className="text-sm text-slate-500">
                                            ხელმძღვანელი: {club.leaderName}
                                        </p>
                                    )}
                                    {club.description && (
                                        <p className="mt-1 text-sm">
                                            {club.description}
                                        </p>
                                    )}
                                </div>
                                <ul className="text-sm text-slate-600">
                                    {club.sessions.map((s, i) => (
                                        <li key={i}>
                                            {WEEKDAYS[s.dayOfWeek]} ·{' '}
                                            {s.startsAt}–{s.endsAt}
                                            {s.location
                                                ? ` · ${s.location}`
                                                : ''}
                                        </li>
                                    ))}
                                </ul>
                                <div>
                                    <div
                                        className="h-2 overflow-hidden rounded-full bg-slate-100"
                                        role="img"
                                        aria-label={`${club.enrolled} / ${club.capacity} ადგილი შევსებულია`}
                                    >
                                        <div
                                            className="h-full bg-[var(--brand-accent)]"
                                            style={{ width: `${percent}%` }}
                                        />
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {club.enrolled}/{club.capacity} ადგილი
                                        შევსებულია ·{' '}
                                        {formatMoney(
                                            club.feeMinor,
                                            club.currency,
                                        )}
                                    </p>
                                </div>
                                {club.children.map((child) => (
                                    <div
                                        key={child.id}
                                        className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-sm"
                                    >
                                        <span>
                                            {child.name}:{' '}
                                            {child.status === 'enrolled'
                                                ? 'ჩარიცხულია'
                                                : child.status === 'waitlisted'
                                                  ? `რიგში #${child.waitPosition}`
                                                  : 'არ არის ჩარიცხული'}
                                        </span>
                                        {child.status === 'none' ? (
                                            <Button
                                                size="sm"
                                                disabled={!club.isOpen}
                                                onClick={() =>
                                                    postWithToast(
                                                        `/portal/clubs/${club.id}/join`,
                                                        {
                                                            student_id:
                                                                child.id,
                                                        },
                                                        full
                                                            ? 'რიგში ჩაეწერა.'
                                                            : 'რეგისტრაცია შესრულდა.',
                                                    )
                                                }
                                            >
                                                {full
                                                    ? 'რიგში ჩაწერა'
                                                    : 'რეგისტრაცია'}
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    postWithToast(
                                                        `/portal/clubs/${club.id}/leave`,
                                                        {
                                                            student_id:
                                                                child.id,
                                                        },
                                                        'გაუქმდა.',
                                                    )
                                                }
                                            >
                                                {child.status === 'enrolled'
                                                    ? 'დატოვება'
                                                    : 'რიგიდან გასვლა'}
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                {club.canSeeRoster && (
                                    <Link
                                        href={`/portal/clubs/${club.id}/roster`}
                                        className="inline-flex min-h-11 items-center text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        მონაწილეები და რიგი
                                    </Link>
                                )}
                                {isManagement && (
                                    <form
                                        className="flex items-end gap-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            postWithToast(
                                                `/portal/clubs/${club.id}/capacity`,
                                                new FormData(e.currentTarget),
                                                'ტევადობა განახლდა.',
                                            );
                                        }}
                                    >
                                        <div>
                                            <label
                                                htmlFor={`cap-${club.id}`}
                                                className="mb-1 block text-xs text-slate-600"
                                            >
                                                ტევადობა
                                            </label>
                                            <Input
                                                id={`cap-${club.id}`}
                                                name="capacity"
                                                type="number"
                                                min={1}
                                                max={500}
                                                defaultValue={club.capacity}
                                                className="w-24"
                                            />
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            type="submit"
                                        >
                                            შენახვა
                                        </Button>
                                    </form>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </PortalLayout>
    );
}
