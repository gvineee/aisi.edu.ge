import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import { store } from '@/routes/enrollment-verification';

type ExistingRequest = {
    status: 'pending' | 'approved' | 'rejected';
    requestedRole: 'student' | 'guardian';
    submittedName: string;
    rejectionReason: string | null;
};

type Props = {
    existingRequest: ExistingRequest | null;
};

const ROLE_LABELS: Record<string, string> = {
    student: 'მოსწავლე',
    guardian: 'მშობელი',
};

export default function VerifyEnrollment({ existingRequest }: Props) {
    const [allowResubmit, setAllowResubmit] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        requested_role: 'guardian' as 'student' | 'guardian',
        national_id: '',
        first_name: '',
        last_name: '',
    });

    if (existingRequest && !(existingRequest.status === 'rejected' && allowResubmit)) {
        return (
            <PortalLayout>
                <Head title="ვერიფიკაცია" />
                <div className="mx-auto max-w-lg rounded-xl border border-slate-200 bg-white p-8 text-center">
                    {existingRequest.status === 'pending' && (
                        <>
                            <h1 className="mb-3 text-xl">
                                მოთხოვნა განხილვის პროცესშია
                            </h1>
                            <p className="text-slate-600">
                                თქვენ მოითხოვეთ წვდომა როგორც{' '}
                                <strong>
                                    {
                                        ROLE_LABELS[
                                            existingRequest.requestedRole
                                        ]
                                    }
                                </strong>{' '}
                                ({existingRequest.submittedName}). ადმინისტრაცია
                                მალე განიხილავს — შედეგს ნახავთ აქვე.
                            </p>
                        </>
                    )}
                    {existingRequest.status === 'approved' && (
                        <>
                            <h1 className="mb-3 text-xl">
                                მოთხოვნა დამტკიცებულია
                            </h1>
                            <p className="text-slate-600">
                                გთხოვთ, გვერდი განაახლოთ — თქვენი პროფილი
                                უკვე მზადაა.
                            </p>
                        </>
                    )}
                    {existingRequest.status === 'rejected' && (
                        <>
                            <h1 className="mb-3 text-xl">
                                მოთხოვნა უარყოფილია
                            </h1>
                            <p className="mb-6 text-slate-600">
                                {existingRequest.rejectionReason ??
                                    'ადმინისტრაციამ ვერ დაადასტურა მითითებული მონაცემები.'}
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => setAllowResubmit(true)}
                            >
                                ხელახლა მოთხოვნა
                            </Button>
                        </>
                    )}
                </div>
            </PortalLayout>
        );
    }

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <PortalLayout>
            <Head title="ვერიფიკაცია" />

            <div className="mx-auto max-w-lg">
                <h1 className="mb-2 text-xl">
                    დაადასტურეთ, ვინ ხართ
                </h1>
                <p className="mb-6 text-sm text-slate-600">
                    სკოლას უკვე აქვს ატვირთული მოსწავლეთა სია. თქვენი (ან
                    შვილის) მონაცემების მითითებით ჩვენ შევადარებთ მას
                    სკოლის ბაზას — ადმინისტრაცია დაადასტურებს დამთხვევას
                    და მოგანიჭებთ წვდომას.
                </p>

                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-1.5">
                        <Label>ვინ ხართ?</Label>
                        <div className="flex gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setData('requested_role', 'guardian')
                                }
                                className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium ${
                                    data.requested_role === 'guardian'
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-300 bg-white'
                                }`}
                            >
                                მშობელი / მეურვე
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setData('requested_role', 'student')
                                }
                                className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium ${
                                    data.requested_role === 'student'
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-300 bg-white'
                                }`}
                            >
                                მოსწავლე
                            </button>
                        </div>
                    </div>

                    <p className="text-sm text-slate-500">
                        {data.requested_role === 'guardian'
                            ? 'შეიყვანეთ თქვენი შვილის მონაცემები:'
                            : 'შეიყვანეთ თქვენი საკუთარი მონაცემები:'}
                    </p>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="first_name">სახელი</Label>
                            <Input
                                id="first_name"
                                value={data.first_name}
                                onChange={(event) =>
                                    setData('first_name', event.target.value)
                                }
                                required
                            />
                            <InputError message={errors.first_name} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="last_name">გვარი</Label>
                            <Input
                                id="last_name"
                                value={data.last_name}
                                onChange={(event) =>
                                    setData('last_name', event.target.value)
                                }
                                required
                            />
                            <InputError message={errors.last_name} />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="national_id">
                            პირადი ნომერი (სასურველია)
                        </Label>
                        <Input
                            id="national_id"
                            value={data.national_id}
                            onChange={(event) =>
                                setData('national_id', event.target.value)
                            }
                            placeholder="01234567890"
                        />
                        <InputError message={errors.national_id} />
                        <p className="text-xs text-slate-500">
                            პირადი ნომრით ყველაზე ზუსტად ხდება დამთხვევა
                            სკოლის ბაზასთან. თუ არ იცით, დატოვეთ ცარიელი —
                            შემოწმდება სახელით/გვარით.
                        </p>
                    </div>

                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-full"
                    >
                        მოთხოვნის გაგზავნა
                    </Button>
                    <InputError message={errors.requested_role} />
                </form>
            </div>
        </PortalLayout>
    );
}
