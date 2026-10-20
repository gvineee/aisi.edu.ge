import { Form } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { store } from '@/actions/App/Http/Controllers/Public/AdmissionLeadController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type VisitSlot = {
    id: number;
    startsAt: string;
    endsAt: string;
    label: string | null;
    location: string | null;
    remaining: number;
};

const dayFormat = new Intl.DateTimeFormat('ka-GE', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'Asia/Tbilisi',
});
const timeFormat = new Intl.DateTimeFormat('ka-GE', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Asia/Tbilisi',
});

function slotText(slot: VisitSlot): string {
    return `${dayFormat.format(new Date(slot.startsAt))}, ${timeFormat.format(new Date(slot.startsAt))}–${timeFormat.format(new Date(slot.endsAt))}`;
}

/**
 * The public visit form (docs/02 section 5.1) — guardian name, one contact,
 * desired grade, explicit consent; never a child's ID or medical history.
 * When the school has published visit times, the family books one here:
 * the server re-checks capacity under a lock, so a slot that filled up in
 * the meantime comes back as a field error and the list is refreshed.
 * Without published times it stays a contact request with a wished date.
 */
export default function VisitRequestDialog({ open, onOpenChange }: Props) {
    const [slots, setSlots] = useState<VisitSlot[] | null>(null);
    const [slotsFailed, setSlotsFailed] = useState(false);
    const [selected, setSelected] = useState('');

    const loadSlots = useCallback(() => {
        setSlotsFailed(false);
        fetch('/admissions/visit-slots', {
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<{ slots: VisitSlot[] }>;
            })
            .then((data) => setSlots(data.slots))
            .catch(() => {
                setSlots([]);
                setSlotsFailed(true);
            });
    }, []);

    useEffect(() => {
        if (open) {
            setSlots(null);
            setSelected('');
            loadSlots();
        }
    }, [open, loadSlots]);

    const bookable = (slots ?? []).filter((slot) => slot.remaining > 0);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>ვიზიტის დაგეგმვა</DialogTitle>
                    <DialogDescription>
                        {bookable.length > 0
                            ? 'აირჩიეთ ვიზიტის დრო და დატოვეთ საკონტაქტო ინფორმაცია.'
                            : 'გაგვიზიარეთ, რომელი კლასით ხართ დაინტერესებული — ჩვენი გუნდი დაგიკავშირდებათ და აგიხსნით შემდეგ ნაბიჯებს.'}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...store.form()}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    onError={(errors) => {
                        if (errors.visit_slot_id) {
                            setSelected('');
                            loadSlots();
                        }
                    }}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {slots === null && (
                                <p
                                    role="status"
                                    className="flex items-center gap-2 text-sm text-slate-600"
                                >
                                    <Spinner /> ვიზიტის დროები იტვირთება…
                                </p>
                            )}

                            {slots !== null && slots.length > 0 && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        ვიზიტის დრო
                                    </legend>
                                    {slots.map((slot) => {
                                        const full = slot.remaining < 1;

                                        return (
                                            <label
                                                key={slot.id}
                                                className={`has-[:checked]:bg-muted has-[:checked]:ring-primary flex min-h-11 cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 ring-slate-200 has-[:checked]:ring-2 ${full ? 'cursor-not-allowed opacity-60' : ''}`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="visit_slot_id"
                                                    value={slot.id}
                                                    disabled={full}
                                                    checked={
                                                        selected ===
                                                        String(slot.id)
                                                    }
                                                    onChange={() =>
                                                        setSelected(
                                                            String(slot.id),
                                                        )
                                                    }
                                                    className="mt-1 size-4"
                                                />
                                                <span className="text-sm">
                                                    <span className="block font-medium">
                                                        {slotText(slot)}
                                                    </span>
                                                    <span className="block text-slate-600">
                                                        {[
                                                            slot.label,
                                                            slot.location,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                        {(slot.label ||
                                                            slot.location) &&
                                                            ' · '}
                                                        {full
                                                            ? 'ადგილები ამოიწურა'
                                                            : `დარჩა ${slot.remaining} ადგილი`}
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                    <label className="has-[:checked]:bg-muted has-[:checked]:ring-primary flex min-h-11 cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 ring-slate-200 has-[:checked]:ring-2">
                                        <input
                                            type="radio"
                                            name="visit_slot_id"
                                            value=""
                                            checked={selected === ''}
                                            onChange={() => setSelected('')}
                                            className="mt-1 size-4"
                                        />
                                        <span className="text-sm">
                                            არცერთი არ მერგება — დამიკავშირდით
                                        </span>
                                    </label>
                                    <InputError
                                        message={errors.visit_slot_id}
                                    />
                                </fieldset>
                            )}

                            {slotsFailed && (
                                <p className="text-sm text-slate-600">
                                    ვიზიტის დროები ვერ ჩაიტვირთა — დატოვეთ
                                    მოთხოვნა და დაგიკავშირდებით.
                                </p>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="guardian_name">სახელი</Label>
                                <Input
                                    id="guardian_name"
                                    name="guardian_name"
                                    required
                                    maxLength={120}
                                    autoComplete="name"
                                />
                                <InputError message={errors.guardian_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="contact_value">
                                    ტელეფონი ან ელფოსტა
                                </Label>
                                <Input
                                    id="contact_value"
                                    name="contact_value"
                                    required
                                    maxLength={190}
                                    autoComplete="tel"
                                    aria-describedby="contact_value_hint"
                                />
                                <p
                                    id="contact_value_hint"
                                    className="text-xs text-slate-500"
                                >
                                    ელფოსტის მითითებისას ჯავშნის დადასტურებას და
                                    შეხსენებას ელფოსტით მიიღებთ.
                                </p>
                                <input
                                    type="hidden"
                                    name="contact_method"
                                    value="phone"
                                />
                                <InputError message={errors.contact_value} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="desired_grade">
                                    სასურველი კლასი (არასავალდებულო)
                                </Label>
                                <Input
                                    id="desired_grade"
                                    name="desired_grade"
                                    maxLength={60}
                                />
                            </div>

                            {selected === '' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="preferred_date">
                                        სასურველი თარიღი (არასავალდებულო)
                                    </Label>
                                    <Input
                                        id="preferred_date"
                                        name="preferred_date"
                                        type="date"
                                    />
                                </div>
                            )}

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="consent_given"
                                    name="consent_given"
                                    required
                                    className="mt-0.5"
                                />
                                <Label
                                    htmlFor="consent_given"
                                    className="text-sm font-normal"
                                >
                                    ვეთანხმები, რომ სკოლა დამიკავშირდეს
                                    მითითებული საკონტაქტო საშუალებით.
                                </Label>
                            </div>
                            <InputError message={errors.consent_given} />

                            <Button
                                type="submit"
                                disabled={processing || slots === null}
                                className="mt-2"
                            >
                                {processing && <Spinner />}
                                {selected === ''
                                    ? 'მოთხოვნის გაგზავნა'
                                    : 'ვიზიტის დაჯავშნა'}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
