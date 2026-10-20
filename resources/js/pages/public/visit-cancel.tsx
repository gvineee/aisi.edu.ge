import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarX2, CircleCheck } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    visit: {
        when: string;
        location: string | null;
        status: 'booked' | 'cancelled';
        slotCancelled: boolean;
    };
    justCancelled: boolean;
};

/**
 * Cancelling a school visit from the signed link in the confirmation
 * e-mail. The link itself is the authorisation (valid until the visit
 * starts); the POST goes back to the same signed URL.
 */
export default function VisitCancel({ visit, justCancelled }: Props) {
    const [processing, setProcessing] = useState(false);
    const cancelled = visit.status === 'cancelled';

    return (
        <PublicLayout>
            <Head title="ვიზიტის გაუქმება">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <section className="mx-auto max-w-xl px-4 py-16">
                <h1 className="mb-6 text-3xl">ვიზიტის გაუქმება</h1>

                <div
                    role="status"
                    className="space-y-4 rounded-xl bg-white p-6 ring-1 ring-slate-200"
                >
                    <p className="text-lg">
                        <strong>{visit.when}</strong>
                        {visit.location && ` · ${visit.location}`}
                    </p>

                    {cancelled ? (
                        <p className="flex items-center gap-2 text-emerald-800">
                            <CircleCheck aria-hidden />
                            {justCancelled
                                ? 'ჯავშანი გაუქმდა. გმადლობთ, რომ შეგვატყობინეთ.'
                                : visit.slotCancelled
                                  ? 'ეს ვიზიტი სკოლამ გააუქმა.'
                                  : 'ეს ჯავშანი უკვე გაუქმებულია.'}
                        </p>
                    ) : (
                        <>
                            <p className="text-slate-700">
                                ვერ მოდიხართ? გაუქმების შემდეგ ადგილი სხვა ოჯახს
                                დარჩება. ახალი დროის არჩევა შეგიძლიათ საიტზე,
                                „ვიზიტის დაგეგმვა“.
                            </p>
                            <Button
                                variant="destructive"
                                disabled={processing}
                                onClick={() =>
                                    router.post(
                                        window.location.href,
                                        {},
                                        {
                                            onStart: () => setProcessing(true),
                                            onFinish: () =>
                                                setProcessing(false),
                                        },
                                    )
                                }
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <CalendarX2 aria-hidden />
                                )}
                                ჯავშნის გაუქმება
                            </Button>
                        </>
                    )}
                </div>
            </section>
        </PublicLayout>
    );
}
