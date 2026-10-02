import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CalendarCheck, MapPin } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDateTime } from '@/lib/assessment';

type TeacherSlot = {
    id: number;
    startsAt: string;
    durationMinutes: number;
    location: string | null;
    capacity: number;
    status: string;
    bookings: {
        id: number;
        studentName: string;
        guardianName: string | null;
        note: string | null;
    }[];
};

type Child = {
    id: number;
    name: string;
    bookings: {
        id: number;
        teacherName: string | null;
        startsAt: string;
        location: string | null;
        slotCancelled: boolean;
    }[];
    slots: {
        id: number;
        teacherName: string | null;
        startsAt: string;
        durationMinutes: number;
        location: string | null;
    }[];
};

type Props = {
    teacherSlots: TeacherSlot[] | null;
    children: Child[] | null;
};

const options = (success: string) => ({
    preserveScroll: true,
    onSuccess: () => toast.success(success),
    onError: (errors: Record<string, string>) =>
        toast.error(firstError(errors) ?? 'ვერ შესრულდა.'),
});

/**
 * Parent–teacher meetings. Teachers publish their own slots; guardians see
 * only slots of their own child's teachers. Capacity is enforced on the
 * server.
 */
export default function AppointmentsIndex({ teacherSlots, children }: Props) {
    const [sending, setSending] = useState(false);

    const createSlot = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        setSending(true);
        router.post('/portal/appointments/slots', new FormData(form), {
            ...options('დრო დაემატა.'),
            onSuccess: () => {
                toast.success('დრო დაემატა.');
                form.reset();
            },
            onFinish: () => setSending(false),
        });
    };

    return (
        <PortalLayout>
            <Head title="შეხვედრები" />
            <h1 className="mb-6 text-2xl">შეხვედრები</h1>

            {children && (
                <section
                    className="mb-10 space-y-6"
                    aria-label="ჩემი შვილების შეხვედრები"
                >
                    {children.length === 0 && (
                        <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                            დაკავშირებული ბავშვი არ არის.
                        </p>
                    )}
                    {children.map((child) => (
                        <div
                            key={child.id}
                            className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                        >
                            <h2 className="mb-3 text-lg">{child.name}</h2>

                            {child.bookings.length > 0 && (
                                <ul className="mb-4 space-y-2">
                                    {child.bookings.map((b) => (
                                        <li
                                            key={b.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-emerald-50 p-3 text-sm"
                                        >
                                            <span>
                                                <strong>{b.teacherName}</strong>{' '}
                                                · {formatDateTime(b.startsAt)}
                                                {b.location
                                                    ? ` · ${b.location}`
                                                    : ''}
                                                {b.slotCancelled && (
                                                    <span className="ml-2 text-red-700">
                                                        მასწავლებელმა გააუქმა
                                                    </span>
                                                )}
                                            </span>
                                            {!b.slotCancelled && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            `/portal/appointments/bookings/${b.id}/cancel`,
                                                            {},
                                                            options(
                                                                'ჯავშანი გაუქმდა.',
                                                            ),
                                                        )
                                                    }
                                                >
                                                    გაუქმება
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {child.slots.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    თავისუფალი დრო ამჟამად არ არის.
                                </p>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {child.slots.map((slot) => (
                                        <li
                                            key={slot.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                                        >
                                            <span>
                                                <strong>
                                                    {slot.teacherName}
                                                </strong>{' '}
                                                ·{' '}
                                                {formatDateTime(slot.startsAt)}{' '}
                                                · {slot.durationMinutes} წთ
                                                {slot.location && (
                                                    <span className="ml-2 inline-flex items-center gap-1 text-slate-500">
                                                        <MapPin
                                                            size={14}
                                                            aria-hidden
                                                        />{' '}
                                                        {slot.location}
                                                    </span>
                                                )}
                                            </span>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/portal/appointments/slots/${slot.id}/book`,
                                                        {
                                                            student_id:
                                                                child.id,
                                                        },
                                                        options('დაიჯავშნა.'),
                                                    )
                                                }
                                            >
                                                დაჯავშნა
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    ))}
                </section>
            )}

            {teacherSlots && (
                <section aria-labelledby="my-slots">
                    <h2 id="my-slots" className="mb-3 text-lg">
                        ჩემი შეხვედრის დროები
                    </h2>
                    <form
                        onSubmit={createSlot}
                        className="mb-4 grid gap-3 rounded-xl bg-white p-5 ring-1 ring-slate-200 sm:grid-cols-2 lg:grid-cols-5 lg:items-end"
                    >
                        <div className="lg:col-span-2">
                            <label
                                htmlFor="slot-start"
                                className="mb-1 block text-sm font-medium"
                            >
                                დრო
                            </label>
                            <Input
                                id="slot-start"
                                name="starts_at"
                                type="datetime-local"
                                required
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="slot-duration"
                                className="mb-1 block text-sm font-medium"
                            >
                                ხანგრძლივობა (წთ)
                            </label>
                            <Input
                                id="slot-duration"
                                name="duration_minutes"
                                type="number"
                                min={5}
                                max={180}
                                defaultValue={15}
                                required
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="slot-location"
                                className="mb-1 block text-sm font-medium"
                            >
                                ადგილი
                            </label>
                            <Input
                                id="slot-location"
                                name="location"
                                maxLength={255}
                                placeholder="მაგ. ოთახი 12 / ონლაინ"
                            />
                        </div>
                        <Button type="submit" disabled={sending}>
                            დამატება
                        </Button>
                    </form>

                    {teacherSlots.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                            <CalendarCheck className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                            <p className="font-medium">
                                შეხვედრის დრო ჯერ არ დაგიმატებიათ.
                            </p>
                        </div>
                    ) : (
                        <ul className="space-y-3">
                            {teacherSlots.map((slot) => (
                                <li
                                    key={slot.id}
                                    className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-medium">
                                            {formatDateTime(slot.startsAt)} ·{' '}
                                            {slot.durationMinutes} წთ
                                            {slot.location
                                                ? ` · ${slot.location}`
                                                : ''}
                                            {slot.status === 'cancelled' && (
                                                <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                                                    გაუქმებული
                                                </span>
                                            )}
                                        </p>
                                        {slot.status === 'open' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/portal/appointments/slots/${slot.id}/cancel`,
                                                        {},
                                                        options('დრო გაუქმდა.'),
                                                    )
                                                }
                                            >
                                                დროის გაუქმება
                                            </Button>
                                        )}
                                    </div>
                                    {slot.bookings.length === 0 ? (
                                        <p className="mt-1 text-sm text-slate-500">
                                            ჯერ არავის დაუჯავშნია.
                                        </p>
                                    ) : (
                                        <ul className="mt-2 space-y-1 text-sm">
                                            {slot.bookings.map((b) => (
                                                <li key={b.id}>
                                                    {b.studentName} ·{' '}
                                                    {b.guardianName}
                                                    {b.note
                                                        ? ` — ${b.note}`
                                                        : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </PortalLayout>
    );
}
