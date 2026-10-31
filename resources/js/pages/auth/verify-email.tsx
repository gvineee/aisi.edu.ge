// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="ელფოსტის დადასტურება" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    დადასტურების ახალი ბმული გაიგზავნა რეგისტრაციისას მითითებულ
                    ელფოსტაზე.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            დადასტურების წერილის ხელახლა გაგზავნა
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            გასვლა
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'ელფოსტის დადასტურება',
    description:
        'ელფოსტის დასადასტურებლად გადადით ბმულზე, რომელიც ახლახან გამოგიგზავნეთ.',
};
