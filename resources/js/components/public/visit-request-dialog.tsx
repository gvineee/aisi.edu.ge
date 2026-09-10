import { Form } from '@inertiajs/react';
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

/**
 * The public "interest" form (docs/02 section 5.1) — guardian name, one
 * contact method, desired grade, explicit consent. Deliberately does not ask
 * for a child's ID or medical history. This submits for real
 * (POST /admissions/leads); it is not a demo that only shows a local
 * success state.
 */
export default function VisitRequestDialog({ open, onOpenChange }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>ვიზიტის დაგეგმვა</DialogTitle>
                    <DialogDescription>
                        გაგვიზიარეთ, რომელი კლასით ხართ დაინტერესებული — ჩვენი
                        გუნდი დაგიკავშირდებათ და აგიხსნით შემდეგ ნაბიჯებს.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...store.form()}
                    resetOnSuccess
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
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
                                />
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
                                disabled={processing}
                                className="mt-2"
                            >
                                {processing && <Spinner />}
                                მოთხოვნის გაგზავნა
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
