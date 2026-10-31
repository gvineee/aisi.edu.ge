import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/passkey-verify';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="პაროლის დადასტურება" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Passkey-ით დადასტურება"
                loadingLabel="დასტურდება..."
                separator="ან დაადასტურეთ პაროლით"
            />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">პაროლი</Label>
                            <PasswordInput
                                id="password"
                                aria-invalid={!!errors.password}
                                aria-describedby={
                                    errors.password
                                        ? 'password_error'
                                        : undefined
                                }
                                name="password"
                                placeholder="პაროლი"
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError
                                id="password_error"
                                message={errors.password}
                            />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                პაროლის დადასტურება
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'პაროლის დადასტურება',
    description:
        'ეს პლატფორმის დაცული ნაწილია. გასაგრძელებლად დაადასტურეთ პაროლი.',
};
