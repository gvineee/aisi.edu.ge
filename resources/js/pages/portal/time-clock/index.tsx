import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Clock, Download, LogIn, LogOut } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Entry = {
    id: number;
    clockInAt: string;
    clockOutAt: string | null;
    minutes: number | null;
    source: string;
    note: string | null;
    correctionReason: string | null;
};

type Props = {
    month: string;
    timezone: string;
    open: { id: number; clockInAt: string } | null;
    mine: Entry[];
    isManager: boolean;
    staff: { id: number; name: string }[];
    summary: {
        userId: number;
        name: string;
        days: number;
        minutes: number;
        open: number;
    }[];
    selected: { userId: number; name: string; entries: Entry[] } | null;
};

const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'ვერ შეინახა.');
const hours = (minutes: number) =>
    (minutes / 60).toLocaleString('ka-GE', { maximumFractionDigits: 1 });

/** "2026-11-05T09:30" in the school's time zone, for datetime-local inputs. */
function localInput(iso: string, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const get = (type: string) =>
        parts.find((p) => p.type === type)?.value ?? '';

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/**
 * Staff mark arrival and departure; management sees the month per person
 * and adds or corrects entries with a reason.
 */
export default function TimeClockIndex({
    month,
    timezone,
    open,
    mine,
    isManager,
    staff,
    summary,
    selected,
}: Props) {
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<Entry | 'new' | null>(null);
    const time = (iso: string) =>
        new Date(iso).toLocaleTimeString('ka-GE', {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone,
        });
    const day = (iso: string) =>
        new Date(iso).toLocaleDateString('ka-GE', {
            day: 'numeric',
            month: 'long',
            weekday: 'short',
            timeZone: timezone,
        });

    const post = (url: string) => {
        setBusy(true);
        router.post(
            url,
            {},
            { preserveScroll: true, onError, onFinish: () => setBusy(false) },
        );
    };
    const go = (params: Record<string, string | number | null>) =>
        router.get(
            '/portal/time-clock',
            Object.fromEntries(
                Object.entries({
                    month,
                    user: selected?.userId ?? null,
                    ...params,
                }).filter(([, v]) => v !== null && v !== ''),
            ),
            { preserveScroll: true },
        );

    const saveEntry = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const existing = editing !== 'new' && editing !== null ? editing : null;
        if (existing) data.append('_method', 'put');
        else if (selected) data.set('user_id', String(selected.userId));
        router.post(
            existing
                ? `/portal/time-clock/entries/${existing.id}`
                : '/portal/time-clock/entries',
            data,
            {
                preserveScroll: true,
                onSuccess: () => setEditing(null),
                onError,
            },
        );
    };

    const table = (entries: Entry[], editable: boolean) =>
        entries.length === 0 ? (
            <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                ამ თვეში ჩანაწერი არ არის.
            </p>
        ) : (
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm">
                {entries.map((entry) => (
                    <li
                        key={entry.id}
                        className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <span>
                            <span className="font-medium">
                                {day(entry.clockInAt)}
                            </span>{' '}
                            <span className="text-slate-600">
                                {time(entry.clockInAt)} –{' '}
                                {entry.clockOutAt ? (
                                    time(entry.clockOutAt)
                                ) : (
                                    <span className="text-amber-700">
                                        წასვლა არ მონიშნულა
                                    </span>
                                )}
                            </span>
                            {(entry.correctionReason || entry.note) && (
                                <span className="block text-xs text-slate-500">
                                    {entry.correctionReason
                                        ? `შესწორება: ${entry.correctionReason}`
                                        : entry.note}
                                </span>
                            )}
                        </span>
                        <span className="flex items-center gap-3">
                            {entry.minutes !== null && (
                                <span className="text-slate-600">
                                    {hours(entry.minutes)} სთ
                                </span>
                            )}
                            {editable && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setEditing(entry)}
                                >
                                    შესწორება
                                </Button>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        );

    const editingEntry = editing !== 'new' && editing !== null ? editing : null;

    return (
        <PortalLayout>
            <Head title="სამუშაო დრო" />
            <h1 className="mb-6 text-2xl">სამუშაო დრო</h1>

            <section
                aria-labelledby="clock"
                className="mb-8 flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-white p-5"
            >
                <Clock className="h-8 w-8 text-slate-400" aria-hidden />
                <div className="flex-1">
                    <h2 id="clock" className="font-medium">
                        {open
                            ? `სამსახურში ხართ ${time(open.clockInAt)}-დან`
                            : 'ამჟამად მოსვლა მონიშნული არ გაქვთ'}
                    </h2>
                    <p className="text-sm text-slate-500">
                        მონიშნეთ სკოლაში მოსვლისას და წასვლისას. ადგილმდებარეობა
                        არ ინახება.
                    </p>
                </div>
                {open ? (
                    <Button
                        size="lg"
                        variant="outline"
                        disabled={busy}
                        onClick={() => post('/portal/time-clock/out')}
                    >
                        <LogOut className="mr-2 h-4 w-4" /> წავედი
                    </Button>
                ) : (
                    <Button
                        size="lg"
                        disabled={busy}
                        onClick={() => post('/portal/time-clock/in')}
                    >
                        <LogIn className="mr-2 h-4 w-4" /> მოვედი
                    </Button>
                )}
            </section>

            <div className="mb-4 flex flex-wrap items-end gap-4">
                <div>
                    <label
                        htmlFor="month"
                        className="mb-1 block text-sm font-medium"
                    >
                        თვე
                    </label>
                    <Input
                        id="month"
                        type="month"
                        value={month}
                        onChange={(e) =>
                            e.target.value && go({ month: e.target.value })
                        }
                    />
                </div>
                {isManager && (
                    <Button asChild variant="outline">
                        <a href={`/portal/time-clock/export?month=${month}`}>
                            <Download className="mr-2 h-4 w-4" /> CSV
                            ბუღალტერიისთვის
                        </a>
                    </Button>
                )}
            </div>

            {isManager ? (
                <>
                    <section aria-labelledby="summary" className="mb-8">
                        <h2 id="summary" className="mb-3 text-lg">
                            თანამშრომლები
                        </h2>
                        {summary.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                                ამ თვეში არავის მოუნიშნავს.
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                                <table className="w-full min-w-[520px] text-sm">
                                    <caption className="sr-only">
                                        სამუშაო დრო თანამშრომლების მიხედვით
                                    </caption>
                                    <thead className="bg-slate-50 text-left text-xs text-slate-600">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 font-medium"
                                            >
                                                თანამშრომელი
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-3 text-right font-medium"
                                            >
                                                დღე
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-3 text-right font-medium"
                                            >
                                                საათი
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right font-medium"
                                            >
                                                დაუსრულებელი
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {summary.map((row) => (
                                            <tr
                                                key={row.userId}
                                                className="border-t border-slate-100"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-4 py-2.5 text-left font-medium"
                                                >
                                                    <button
                                                        type="button"
                                                        className="min-h-11 hover:underline"
                                                        onClick={() =>
                                                            go({
                                                                user: row.userId,
                                                            })
                                                        }
                                                    >
                                                        {row.name}
                                                    </button>
                                                </th>
                                                <td className="px-3 py-2.5 text-right">
                                                    {row.days}
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    {hours(row.minutes)}
                                                </td>
                                                <td
                                                    className={`px-4 py-2.5 text-right ${row.open > 0 ? 'text-amber-700' : ''}`}
                                                >
                                                    {row.open}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section aria-labelledby="person" className="mb-8">
                        <div className="mb-3 flex flex-wrap items-end gap-3">
                            <div>
                                <label
                                    htmlFor="person-select"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    თანამშრომლის ჩანაწერები
                                </label>
                                <select
                                    id="person-select"
                                    value={selected?.userId ?? ''}
                                    onChange={(e) =>
                                        go({ user: e.target.value || null })
                                    }
                                    className="h-11 rounded-md border border-slate-300 bg-white px-3 text-sm"
                                >
                                    <option value="">აირჩიეთ</option>
                                    {staff.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {selected && (
                                <Button
                                    variant="outline"
                                    onClick={() => setEditing('new')}
                                >
                                    ჩანაწერის დამატება
                                </Button>
                            )}
                        </div>
                        <h2 id="person" className="sr-only">
                            {selected?.name ?? 'თანამშრომელი'}
                        </h2>
                        {selected && table(selected.entries, true)}
                        {editing !== null && selected && (
                            <form
                                key={editingEntry?.id ?? 'new'}
                                onSubmit={saveEntry}
                                className="mt-4 grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_2fr_auto] lg:items-end"
                            >
                                <div>
                                    <label
                                        htmlFor="clock_in_at"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        მოსვლა
                                    </label>
                                    <Input
                                        id="clock_in_at"
                                        name="clock_in_at"
                                        type="datetime-local"
                                        required
                                        defaultValue={
                                            editingEntry
                                                ? localInput(
                                                      editingEntry.clockInAt,
                                                      timezone,
                                                  )
                                                : ''
                                        }
                                    />
                                </div>
                                <div>
                                    <label
                                        htmlFor="clock_out_at"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        წასვლა
                                    </label>
                                    <Input
                                        id="clock_out_at"
                                        name="clock_out_at"
                                        type="datetime-local"
                                        defaultValue={
                                            editingEntry?.clockOutAt
                                                ? localInput(
                                                      editingEntry.clockOutAt,
                                                      timezone,
                                                  )
                                                : ''
                                        }
                                    />
                                </div>
                                <div>
                                    <label
                                        htmlFor="reason"
                                        className="mb-1 block text-sm font-medium"
                                    >
                                        მიზეზი
                                    </label>
                                    <Input
                                        id="reason"
                                        name="reason"
                                        required
                                        maxLength={500}
                                        placeholder="მაგ. დაავიწყდა წასვლის მონიშვნა"
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit">შენახვა</Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setEditing(null)}
                                    >
                                        გაუქმება
                                    </Button>
                                </div>
                            </form>
                        )}
                    </section>
                </>
            ) : null}

            <section aria-labelledby="mine">
                <h2 id="mine" className="mb-3 text-lg">
                    ჩემი ჩანაწერები
                </h2>
                {table(mine, false)}
            </section>
        </PortalLayout>
    );
}
