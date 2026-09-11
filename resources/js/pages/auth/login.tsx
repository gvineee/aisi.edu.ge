import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="ჩემი აისი — შესვლა" />

            <PasskeyVerify
                label="Passkey-ით შესვლა"
                loadingLabel="მიმდინარეობს შემოწმება..."
                separator="ან გამოიყენეთ ელფოსტა"
            />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">ელფოსტა</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="name@example.com"
                                    className="h-12 border-slate-300 bg-white px-4 focus-visible:border-[#f5683c] focus-visible:ring-[#f5683c]/20"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">პაროლი</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            დაგავიწყდათ პაროლი?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="შეიყვანეთ პაროლი"
                                    className="h-12 border-slate-300 bg-white px-4 focus-visible:border-[#f5683c] focus-visible:ring-[#f5683c]/20"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">დამახსოვრება</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-3 h-12 w-full bg-[#f5683c] font-bold text-white hover:bg-[#db542e]"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                შესვლა
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            არ გაქვთ ანგარიში?{' '}
                            <TextLink href={register()} tabIndex={5}>
                                რეგისტრაცია
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'კეთილი იყოს თქვენი დაბრუნება',
    description:
        'შედით მშობლის, მოსწავლის, მასწავლებლის ან თანამშრომლის ანგარიშზე.',
};
