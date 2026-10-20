import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CalendarPlus, MapPin, Users } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { firstError, formatDateTime } from '@/lib/assessment';

type Booking = {
    id: number;
    status: 'booked' | 'cancelled';
    leadId: number;
    guardianName: string;
    contactValue: string;
    desiredGrade: string | null;
    bookedAt: string | null;
    cancelledByFamily: boolean;
};

type Slot = {
    id: number;
    startsAt: string;
    endsAt: string;
    label: string | null;
    location: string | null;
    capacity: number;
    bookedCount: number;
    status: 'open' | 'cancelled';
    cancelReason: string | null;
    isPast: boolean;
    bookings: Booking[];
};

type Props = {
    view: 'upcoming' | 'past';
    slots: Slot[];
};

const timeOnly = new Intl.DateTimeFormat('ka-GE', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Asia/Tbilisi',
});

const options = (success: string, onDone?: () => void) => ({
    preserveScroll: true,
    onSuccess: () => {
        toast.success(success);
        onDone?.();
    },
    onError: (errors: Record<string, string>) =>
        toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
});

/**
 * School visits for the admissions team: publish visit times with a
 * family capacity (optionally repeated weekly), see who booked through the
 * public visit form, cancel a booking or a whole visit. Capacity is
 * enforced on the server.
 */
export default function SchoolVisitsIndex({ view, slots }: Props) {
    const [sending, setSending] = useState(false);

    const createSlot = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        router.post('/portal/school-visits', new FormData(form), {
            ...options('ვიზიტის დრო დაემატა.', () => form.reset()),
            onStart: () => setSending(true),
            onFinish: () => setSending(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="სკოლის ვიზიტები" />
            <h1 className="mb-1 text-2xl">სკოლის ვიზიტები</h1>
            <p className="mb-6 text-sm text-slate-600">
                აქ დამატებული დროები ჩანს საიტის ფორმაში „ვიზიტის დაგეგმვა“.
                ოჯახი ირჩევს დროს, ჯავშანი კი{' '}
                <Link
                    href="/portal/admissions-pipeline"
                    className="underline underline-offset-4"
                >
                    მიღების პროცესში
                </Link>{' '}
                ახალ ლიდად ჩნდება.
            </p>

            <form
                onSubmit={createSlot}
                className="mb-8 grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-3"
                aria-labelledby="new-slot"
            >
                <h2 id="new-slot" className="text-lg sm:col-span-3">
                    ახალი ვიზიტის დრო
                </h2>
                <div className="grid gap-1">
                    <Label htmlFor="slot-date">თარიღი</Label>
                    <Input id="slot-date" name="date" type="date" required />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="slot-time">დაწყება</Label>
                    <Input id="slot-time" name="time" type="time" required />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="slot-duration">ხანგრძლივობა (წთ)</Label>
                    <Input
                        id="slot-duration"
                        name="duration"
                        type="number"
                        min={15}
                        max={480}
                        step={5}
                        defaultValue={60}
                        required
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="slot-capacity">ადგილები (ოჯახი)</Label>
                    <Input
                        id="slot-capacity"
                        name="capacity"
                        type="number"
                        min={1}
                        max={200}
                        defaultValue={5}
                        required
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="slot-label">დასახელება</Label>
                    <Input
                        id="slot-label"
                        name="label"
                        maxLength={120}
                        placeholder="მაგ. ღია კარის დღე"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="slot-location">ადგილი</Label>
                    <Input id="slot-location" name="location" maxLength={160} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="slot-repeat">გამეორება (კვირა, 0–12)</Label>
                    <Input
                        id="slot-repeat"
                        name="repeat_weeks"
                        type="number"
                        min={0}
                        max={12}
                        defaultValue={0}
                    />
                </div>
                <div className="flex items-end sm:col-span-2">
                    <Button type="submit" disabled={sending}>
                        <CalendarPlus aria-hidden /> დამატება
                    </Button>
                </div>
            </form>

            <nav aria-label="პერიოდი" className="mb-4 flex gap-2">
                {(['upcoming', 'past'] as const).map((key) => (
                    <Link
                        key={key}
                        href={
                            key === 'past'
                                ? '/portal/school-visits?view=past'
                                : '/portal/school-visits'
                        }
                        aria-current={view === key ? 'page' : undefined}
                        className={`inline-flex min-h-11 items-center rounded-lg px-4 text-sm ring-1 ring-slate-200 ${view === key ? 'bg-slate-900 text-white' : 'bg-white hover:bg-slate-50'}`}
                    >
                        {key === 'past' ? 'გასული (90 დღე)' : 'მომავალი'}
                    </Link>
                ))}
            </nav>

            {slots.length === 0 && (
                <p className="rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
                    {view === 'past'
                        ? 'ბოლო 90 დღეში ვიზიტი არ ყოფილა.'
                        : 'მომავალი ვიზიტის დრო ჯერ არ არის. სანამ არ დაამატებთ, საიტის ფორმა მხოლოდ საკონტაქტო მოთხოვნას იღებს.'}
                </p>
            )}

            <ul className="space-y-4">
                {slots.map((slot) => (
                    <SlotCard key={slot.id} slot={slot} />
                ))}
            </ul>
        </PortalLayout>
    );
}

function SlotCard({ slot }: { slot: Slot }) {
    const [cancelReason, setCancelReason] = useState('');
    const cancelled = slot.status === 'cancelled';
    const active = slot.bookings.filter((b) => b.status === 'booked');
    const inactive = slot.bookings.filter((b) => b.status !== 'booked');

    return (
        <li
            className={`rounded-xl bg-white p-4 ring-1 ring-slate-200 ${cancelled ? 'opacity-70' : ''}`}
        >
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="font-medium">
                    {formatDateTime(slot.startsAt)}–
                    {timeOnly.format(new Date(slot.endsAt))}
                    {slot.label && ` · ${slot.label}`}
                </h3>
                <span className="inline-flex items-center gap-1 text-sm text-slate-600">
                    <Users size={14} aria-hidden />
                    {cancelled
                        ? 'გაუქმებული'
                        : `${slot.bookedCount} / ${slot.capacity}`}
                </span>
            </div>
            {slot.location && (
                <p className="mb-2 inline-flex items-center gap-1 text-sm text-slate-600">
                    <MapPin size={14} aria-hidden /> {slot.location}
                </p>
            )}
            {cancelled && slot.cancelReason && (
                <p className="mb-2 text-sm text-slate-600">
                    მიზეზი: {slot.cancelReason}
                </p>
            )}

            {active.length === 0 && !cancelled && (
                <p className="text-sm text-slate-500">ჯავშანი ჯერ არ არის.</p>
            )}
            {active.length > 0 && (
                <ul className="divide-y divide-slate-100 text-sm">
                    {active.map((b) => (
                        <li
                            key={b.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <span>
                                <Link
                                    href={`/portal/admissions-pipeline/${b.leadId}`}
                                    className="inline-flex min-h-11 items-center font-medium underline-offset-4 hover:underline"
                                >
                                    {b.guardianName}
                                </Link>{' '}
                                · {b.contactValue}
                                {b.desiredGrade && ` · ${b.desiredGrade}`}
                            </span>
                            {!slot.isPast && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/portal/school-visit-bookings/${b.id}/cancel`,
                                            {},
                                            options('ჯავშანი გაუქმდა.'),
                                        )
                                    }
                                >
                                    ჯავშნის გაუქმება
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {inactive.length > 0 && (
                <p className="mt-2 text-xs text-slate-500">
                    გაუქმებული ჯავშნები:{' '}
                    {inactive
                        .map(
                            (b) =>
                                `${b.guardianName}${b.cancelledByFamily ? ' (ოჯახმა გააუქმა)' : ''}`,
                        )
                        .join(', ')}
                </p>
            )}

            {!cancelled && !slot.isPast && (
                <form
                    className="mt-3 flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(
                            `/portal/school-visits/${slot.id}/cancel`,
                            { reason: cancelReason },
                            options('ვიზიტი გაუქმდა.'),
                        );
                    }}
                >
                    <div className="min-w-[200px] flex-1">
                        <Label
                            htmlFor={`cancel-${slot.id}`}
                            className="mb-1 block text-sm"
                        >
                            ვიზიტის გაუქმების მიზეზი
                        </Label>
                        <Input
                            id={`cancel-${slot.id}`}
                            value={cancelReason}
                            onChange={(e) => setCancelReason(e.target.value)}
                            required
                            minLength={5}
                            maxLength={500}
                        />
                    </div>
                    <Button type="submit" variant="destructive">
                        ვიზიტის გაუქმება
                    </Button>
                </form>
            )}
        </li>
    );
}
