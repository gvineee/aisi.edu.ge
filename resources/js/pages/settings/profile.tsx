import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';
import { send } from '@/routes/verification';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="პროფილის პარამეტრები" />

            <h1 className="sr-only">პროფილის პარამეტრები</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="პროფილი"
                    description="განაახლეთ სახელი, გვარი და ელფოსტა"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">სახელი და გვარი</Label>

                                <Input
                                    id="name"
                                    aria-invalid={!!errors.name}
                                    aria-describedby={
                                        errors.name ? 'name_error' : undefined
                                    }
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="სახელი და გვარი"
                                />

                                <InputError
                                    className="mt-2"
                                    id="name_error"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">ელფოსტა</Label>

                                <Input
                                    id="email"
                                    aria-invalid={!!errors.email}
                                    aria-describedby={
                                        errors.email ? 'email_error' : undefined
                                    }
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="name@example.com"
                                />

                                <InputError
                                    className="mt-2"
                                    id="email_error"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="text-muted-foreground -mt-4 text-sm">
                                            თქვენი ელფოსტა დადასტურებული არ
                                            არის.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                დადასტურების წერილის ხელახლა
                                                გაგზავნა
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                დადასტურების ახალი ბმული
                                                გაიგზავნა თქვენს ელფოსტაზე.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    შენახვა
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'პროფილის პარამეტრები',
            href: edit(),
        },
    ],
};
