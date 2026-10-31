import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <>
            <Head title="ახალი პაროლის დაყენება" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">ელფოსტა</Label>
                            <Input
                                id="email"
                                aria-invalid={!!errors.email}
                                aria-describedby={
                                    errors.email ? 'email_error' : undefined
                                }
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                            />
                            <InputError
                                id="email_error"
                                message={errors.email}
                                className="mt-2"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">ახალი პაროლი</Label>
                            <PasswordInput
                                id="password"
                                aria-invalid={!!errors.password}
                                aria-describedby={
                                    errors.password
                                        ? 'password_error'
                                        : undefined
                                }
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder="ახალი პაროლი"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                id="password_error"
                                message={errors.password}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                პაროლის დადასტურება
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                aria-invalid={!!errors.password_confirmation}
                                aria-describedby={
                                    errors.password_confirmation
                                        ? 'password_confirmation_error'
                                        : undefined
                                }
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder="პაროლის დადასტურება"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                id="password_confirmation_error"
                                message={errors.password_confirmation}
                                className="mt-2"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            პაროლის შეცვლა
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'ახალი პაროლის დაყენება',
    description: 'შეიყვანეთ ახალი პაროლი',
};
