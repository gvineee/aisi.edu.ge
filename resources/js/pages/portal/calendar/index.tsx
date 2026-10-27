import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDay, formatMonth } from '@/lib/assessment';

type Entry = {
    key: string;
    id: number | null;
    kind: 'event' | 'holiday';
    title: string;
    description: string | null;
    category: string;
    startsOn: string;
    endsOn: string;
    startsAt: string | null;
    endsAt: string | null;
    location: string | null;
    audience: 'everyone' | 'staff' | 'classes';
    classes: { id: number; name: string }[];
};

type Props = {
    month: string;
    today: string;
    entries: Entry[];
    canManage: boolean;
    classes: { id: number; name: string }[];
};

const CATEGORIES: Record<string, { label: string; chip: string }> = {
    event: { label: 'ღონისძიება', chip: 'bg-sky-100 text-sky-900' },
    exam: { label: 'შემაჯამებელი / ტესტი', chip: 'bg-rose-100 text-rose-900' },
    meeting: { label: 'შეხვედრა', chip: 'bg-violet-100 text-violet-900' },
    trip: { label: 'ექსკურსია', chip: 'bg-emerald-100 text-emerald-900' },
    other: { label: 'სხვა', chip: 'bg-slate-100 text-slate-800' },
    holiday: {
        label: 'არდადეგები / დასვენება',
        chip: 'bg-amber-100 text-amber-900',
    },
};

const AUDIENCES: Record<Entry['audience'], string> = {
    everyone: 'ყველასთვის',
    staff: 'მხოლოდ პერსონალისთვის',
    classes: 'არჩეული კლასებისთვის',
};

const WEEKDAYS = ['ორშ', 'სამ', 'ოთხ', 'ხუთ', 'პარ', 'შაბ', 'კვი'];

const iso = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

const shiftMonth = (month: string, by: number) => {
    const [y, m] = month.split('-').map(Number);
    const date = new Date(y, m - 1 + by, 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
};

const onDay = (entry: Entry, day: string) =>
    entry.startsOn <= day && entry.endsOn >= day;

const when = (entry: Entry) => {
    const days =
        entry.startsOn === entry.endsOn
            ? formatDay(entry.startsOn)
            : `${formatDay(entry.startsOn)} – ${formatDay(entry.endsOn)}`;

    return entry.startsAt
        ? `${days}, ${entry.startsAt}${entry.endsAt ? `–${entry.endsAt}` : ''}`
        : days;
};

/**
 * The school calendar. Everyone sees the events meant for them (the server
 * decides); management adds and edits them. Holidays come from the
 * worked-hours calendar and are read-only here.
 */
export default function CalendarIndex({
    month,
    today,
    entries,
    canManage,
    classes,
}: Props) {
    const [open, setOpen] = useState<Entry | null>(null);
    const [editing, setEditing] = useState<Entry | 'new' | null>(null);

    const go = (target: string) =>
        router.get(
            '/portal/calendar',
            { month: target },
            { preserveState: false },
        );

    const [y, m] = month.split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const lead = (first.getDay() + 6) % 7; // Monday first
    const daysInMonth = new Date(y, m, 0).getDate();
    const cells: (string | null)[] = [
        ...Array.from({ length: lead }, () => null),
        ...Array.from({ length: daysInMonth }, (_, i) =>
            iso(new Date(y, m - 1, i + 1)),
        ),
    ];
    while (cells.length % 7 !== 0) cells.push(null);

    const days = Array.from({ length: daysInMonth }, (_, i) =>
        iso(new Date(y, m - 1, i + 1)),
    )
        .map((day) => ({
            day,
            items: entries.filter((entry) => onDay(entry, day)),
        }))
        .filter(({ items }) => items.length > 0);

    return (
        <PortalLayout>
            <Head title="კალენდარი" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-2xl">კალენდარი</h1>
                {canManage && (
                    <Button onClick={() => setEditing('new')}>
                        <Plus className="mr-2 h-4 w-4" /> ახალი ღონისძიება
                    </Button>
                )}
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="წინა თვე"
                    onClick={() => go(shiftMonth(month, -1))}
                >
                    <ChevronLeft className="h-4 w-4" />
                </Button>
                <h2 className="min-w-44 text-center text-lg capitalize">
                    {formatMonth(month)}
                </h2>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="შემდეგი თვე"
                    onClick={() => go(shiftMonth(month, 1))}
                >
                    <ChevronRight className="h-4 w-4" />
                </Button>
                {today.slice(0, 7) !== month && (
                    <Button
                        variant="ghost"
                        onClick={() => go(today.slice(0, 7))}
                    >
                        დღეს
                    </Button>
                )}
            </div>

            <ul
                className="mb-6 flex flex-wrap gap-2 text-xs"
                aria-label="ფერების აღწერა"
            >
                {Object.entries(CATEGORIES).map(([key, category]) => (
                    <li
                        key={key}
                        className={`rounded-full px-2.5 py-1 ${category.chip}`}
                    >
                        {category.label}
                    </li>
                ))}
            </ul>

            {/* Month grid from md up; the list below is the mobile view. */}
            <div className="mb-8 hidden overflow-hidden rounded-xl border border-slate-200 bg-white md:block">
                <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs text-slate-600">
                    {WEEKDAYS.map((weekday) => (
                        <div key={weekday} className="py-2 font-medium">
                            {weekday}
                        </div>
                    ))}
                </div>
                <div className="grid grid-cols-7">
                    {cells.map((day, index) => {
                        const items = day
                            ? entries.filter((entry) => onDay(entry, day))
                            : [];

                        return (
                            <div
                                key={day ?? `blank-${index}`}
                                className={`min-h-28 border-slate-100 p-1.5 ${index % 7 !== 6 ? 'border-r' : ''} ${index >= 7 ? 'border-t' : ''} ${day === null ? 'bg-slate-50/60' : ''}`}
                            >
                                {day && (
                                    <>
                                        <div
                                            className={`mb-1 flex h-6 w-6 items-center justify-center rounded-full text-xs ${day === today ? 'bg-slate-900 font-semibold text-white' : 'text-slate-500'}`}
                                        >
                                            {Number(day.slice(8))}
                                        </div>
                                        <ul className="space-y-1">
                                            {items.slice(0, 3).map((entry) => (
                                                <li key={entry.key}>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setOpen(entry)
                                                        }
                                                        className={`w-full truncate rounded px-1.5 py-0.5 text-left text-xs ${CATEGORIES[entry.category]?.chip ?? CATEGORIES.other.chip}`}
                                                    >
                                                        {entry.startsAt &&
                                                        day === entry.startsOn
                                                            ? `${entry.startsAt} `
                                                            : ''}
                                                        {entry.title}
                                                    </button>
                                                </li>
                                            ))}
                                            {items.length > 3 && (
                                                <li className="px-1.5 text-xs text-slate-500">
                                                    +{items.length - 3} სხვა
                                                </li>
                                            )}
                                        </ul>
                                    </>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <section aria-labelledby="month-list">
                <h2 id="month-list" className="mb-3 text-lg md:sr-only">
                    თვის ღონისძიებები
                </h2>
                {days.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                        <CalendarDays className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                        <p className="font-medium">
                            ამ თვეში ჩანაწერი არ არის.
                        </p>
                        {canManage && (
                            <p className="mt-1 text-sm text-slate-500">
                                დაამატეთ ღონისძიება, ტესტის დღე ან შეხვედრა.
                            </p>
                        )}
                    </div>
                ) : (
                    <ol className="space-y-4 md:hidden">
                        {days.map(({ day, items }) => (
                            <li key={day}>
                                <h3
                                    className={`mb-1 text-sm ${day === today ? 'font-semibold' : 'text-slate-600'}`}
                                >
                                    {formatDay(day)}
                                    {day === today && ' · დღეს'}
                                </h3>
                                <ul className="space-y-2">
                                    {items.map((entry) => (
                                        <li key={entry.key}>
                                            <button
                                                type="button"
                                                onClick={() => setOpen(entry)}
                                                className="flex min-h-11 w-full items-start gap-3 rounded-lg border border-slate-200 bg-white p-3 text-left"
                                            >
                                                <span
                                                    className={`mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-xs ${CATEGORIES[entry.category]?.chip ?? CATEGORIES.other.chip}`}
                                                >
                                                    {entry.startsAt ??
                                                        'მთელი დღე'}
                                                </span>
                                                <span className="font-medium">
                                                    {entry.title}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <EntryDialog
                entry={open}
                canManage={canManage}
                onClose={() => setOpen(null)}
                onEdit={(entry) => {
                    setOpen(null);
                    setEditing(entry);
                }}
            />
            {canManage && (
                <EventForm
                    key={editing === 'new' ? 'new' : (editing?.key ?? 'closed')}
                    event={editing}
                    classes={classes}
                    defaultDay={
                        today.slice(0, 7) === month ? today : `${month}-01`
                    }
                    onClose={() => setEditing(null)}
                />
            )}
        </PortalLayout>
    );
}

function EntryDialog({
    entry,
    canManage,
    onClose,
    onEdit,
}: {
    entry: Entry | null;
    canManage: boolean;
    onClose: () => void;
    onEdit: (entry: Entry) => void;
}) {
    return (
        <Dialog
            open={entry !== null}
            onOpenChange={(value) => !value && onClose()}
        >
            {entry && (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{entry.title}</DialogTitle>
                        <DialogDescription>{when(entry)}</DialogDescription>
                    </DialogHeader>
                    <dl className="space-y-2 text-sm">
                        <div>
                            <dt className="sr-only">სახეობა</dt>
                            <dd>
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs ${CATEGORIES[entry.category]?.chip ?? CATEGORIES.other.chip}`}
                                >
                                    {CATEGORIES[entry.category]?.label ??
                                        entry.category}
                                </span>
                            </dd>
                        </div>
                        {entry.location && (
                            <div className="flex gap-2">
                                <dt className="text-slate-500">ადგილი:</dt>
                                <dd>{entry.location}</dd>
                            </div>
                        )}
                        {entry.kind === 'event' && (
                            <div className="flex gap-2">
                                <dt className="text-slate-500">ვისთვის:</dt>
                                <dd>
                                    {entry.audience === 'classes'
                                        ? entry.classes
                                              .map((c) => c.name)
                                              .join(', ')
                                        : AUDIENCES[entry.audience]}
                                </dd>
                            </div>
                        )}
                        {entry.description && (
                            <div>
                                <dt className="sr-only">აღწერა</dt>
                                <dd className="whitespace-pre-line text-slate-700">
                                    {entry.description}
                                </dd>
                            </div>
                        )}
                        {entry.kind === 'holiday' && (
                            <p className="text-slate-500">
                                გაკვეთილები ამ დღეებში არ ტარდება.
                            </p>
                        )}
                    </dl>
                    {canManage && entry.kind === 'event' && (
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => onEdit(entry)}
                            >
                                რედაქტირება
                            </Button>
                        </DialogFooter>
                    )}
                </DialogContent>
            )}
        </Dialog>
    );
}

function EventForm({
    event,
    classes,
    defaultDay,
    onClose,
}: {
    event: Entry | 'new' | null;
    classes: { id: number; name: string }[];
    defaultDay: string;
    onClose: () => void;
}) {
    const existing = event !== null && event !== 'new' ? event : null;
    const [audience, setAudience] = useState<Entry['audience']>(
        existing?.audience ?? 'everyone',
    );
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const options = {
        preserveScroll: true,
        onSuccess: onClose,
        onError: (errors: Record<string, string>) =>
            toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.'),
        onFinish: () => setSaving(false),
    };

    const submit = (formEvent: FormEvent<HTMLFormElement>) => {
        formEvent.preventDefault();
        const data = new FormData(formEvent.currentTarget);
        setSaving(true);

        if (existing) {
            data.append('_method', 'put');
            router.post(
                `/portal/calendar/events/${existing.id}`,
                data,
                options,
            );
        } else {
            router.post('/portal/calendar/events', data, options);
        }
    };

    const remove = () => {
        if (!existing) return;
        setSaving(true);
        router.delete(`/portal/calendar/events/${existing.id}`, options);
    };

    const field =
        'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

    return (
        <Dialog
            open={event !== null}
            onOpenChange={(value) => !value && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {existing
                            ? 'ღონისძიების რედაქტირება'
                            : 'ახალი ღონისძიება'}
                    </DialogTitle>
                    <DialogDescription>
                        ჩანაწერს დაინახავს მხოლოდ ის, ვისთვისაც არის
                        განკუთვნილი.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="grid grid-cols-1 gap-4 sm:grid-cols-2"
                >
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="title"
                            className="mb-1 block text-sm font-medium"
                        >
                            დასახელება
                        </label>
                        <Input
                            id="title"
                            name="title"
                            required
                            maxLength={160}
                            defaultValue={existing?.title}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="category"
                            className="mb-1 block text-sm font-medium"
                        >
                            სახეობა
                        </label>
                        <select
                            id="category"
                            name="category"
                            className={field}
                            defaultValue={existing?.category ?? 'event'}
                        >
                            {Object.entries(CATEGORIES)
                                .filter(([key]) => key !== 'holiday')
                                .map(([key, category]) => (
                                    <option key={key} value={key}>
                                        {category.label}
                                    </option>
                                ))}
                        </select>
                    </div>
                    <div>
                        <label
                            htmlFor="location"
                            className="mb-1 block text-sm font-medium"
                        >
                            ადგილი
                        </label>
                        <Input
                            id="location"
                            name="location"
                            maxLength={160}
                            defaultValue={existing?.location ?? ''}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="starts_on"
                            className="mb-1 block text-sm font-medium"
                        >
                            დაწყება
                        </label>
                        <Input
                            id="starts_on"
                            name="starts_on"
                            type="date"
                            required
                            defaultValue={existing?.startsOn ?? defaultDay}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="ends_on"
                            className="mb-1 block text-sm font-medium"
                        >
                            დასრულება
                        </label>
                        <Input
                            id="ends_on"
                            name="ends_on"
                            type="date"
                            defaultValue={existing?.endsOn ?? ''}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="starts_at"
                            className="mb-1 block text-sm font-medium"
                        >
                            დრო (დან)
                        </label>
                        <Input
                            id="starts_at"
                            name="starts_at"
                            type="time"
                            defaultValue={existing?.startsAt ?? ''}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="ends_at"
                            className="mb-1 block text-sm font-medium"
                        >
                            დრო (მდე)
                        </label>
                        <Input
                            id="ends_at"
                            name="ends_at"
                            type="time"
                            defaultValue={existing?.endsAt ?? ''}
                        />
                    </div>
                    <p className="-mt-2 text-xs text-slate-500 sm:col-span-2">
                        დროის გარეშე ჩანაწერი მთელი დღისაა.
                    </p>
                    <fieldset className="sm:col-span-2">
                        <legend className="mb-1 text-sm font-medium">
                            ვისთვის
                        </legend>
                        <div className="flex flex-wrap gap-4 text-sm">
                            {(
                                Object.keys(AUDIENCES) as Entry['audience'][]
                            ).map((key) => (
                                <label
                                    key={key}
                                    className="flex min-h-11 items-center gap-2"
                                >
                                    <input
                                        type="radio"
                                        name="audience"
                                        value={key}
                                        checked={audience === key}
                                        onChange={() => setAudience(key)}
                                    />
                                    {AUDIENCES[key]}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    {audience === 'classes' && (
                        <fieldset className="sm:col-span-2">
                            <legend className="mb-1 text-sm font-medium">
                                კლასები
                            </legend>
                            {classes.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    ამ სასწავლო წელს კლასები არ არის.
                                </p>
                            ) : (
                                <div className="grid grid-cols-3 gap-1 text-sm sm:grid-cols-5">
                                    {classes.map((schoolClass) => (
                                        <label
                                            key={schoolClass.id}
                                            className="flex min-h-11 items-center gap-2"
                                        >
                                            <input
                                                type="checkbox"
                                                name="class_ids[]"
                                                value={schoolClass.id}
                                                defaultChecked={existing?.classes.some(
                                                    (c) =>
                                                        c.id === schoolClass.id,
                                                )}
                                            />
                                            {schoolClass.name}
                                        </label>
                                    ))}
                                </div>
                            )}
                        </fieldset>
                    )}
                    <div className="sm:col-span-2">
                        <label
                            htmlFor="description"
                            className="mb-1 block text-sm font-medium"
                        >
                            აღწერა
                        </label>
                        <textarea
                            id="description"
                            name="description"
                            rows={4}
                            maxLength={5000}
                            defaultValue={existing?.description ?? ''}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                        />
                    </div>
                    <DialogFooter className="gap-2 sm:col-span-2">
                        {existing &&
                            (confirmDelete ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={saving}
                                    onClick={remove}
                                >
                                    დიახ, წაშალე
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setConfirmDelete(true)}
                                >
                                    წაშლა
                                </Button>
                            ))}
                        <Button type="submit" disabled={saving}>
                            შენახვა
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
