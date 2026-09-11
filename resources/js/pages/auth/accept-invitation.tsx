import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import * as InvitationController from '@/actions/App/Http/Controllers/Public/InvitationController';

type Props =
    | { status: 'accepted' | 'expired' }
    | {
          status: 'valid';
          token: string;
          email: string;
          roleLabel: string;
          tenantName: string;
          userExists: boolean;
          passwordRules: string;
      };

const STATUS_MESSAGES: Record<'accepted' | 'expired', string> = {
    accepted: 'ეს მოწვევა უკვე გამოყენებულია.',
    expired: 'ამ მოწვევის ვადა ამოიწურა. მიმართეთ ადმინისტრაციას ახალი მოწვევისთვის.',
};

export default function AcceptInvitation(props: Props) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    if (props.status !== 'valid') {
        return (
            <>
                <Head title="მოწვევა" />
                <div className="space-y-4 text-center">
                    <p className="text-muted-foreground">
                        {STATUS_MESSAGES[props.status]}
                    </p>
                </div>
            </>
        );
    }

    const { token, email, roleLabel, tenantName, userExists, passwordRules } =
        props;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setProcessing(true);
        router.post(InvitationController.accept.url(token), form, {
            onError: (formErrors) => {
                setErrors(formErrors as Record<string, string>);
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <Head title="მოწვევის მიღება" />
            <div className="space-y-2 text-center">
                <h1 className="text-xl font-medium">{tenantName}</h1>
                <p className="text-muted-foreground text-sm">
                    გეპატიჟებით როლით: <strong>{roleLabel}</strong>
                </p>
            </div>

            <form onSubmit={submit} className="grid gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="email">ელფოსტა</Label>
                    <Input id="email" type="email" value={email} readOnly />
                </div>

                {userExists ? (
                    <p className="text-muted-foreground text-sm">
                        ამ ელფოსტით ანგარიში უკვე არსებობს — თქვენ მხოლოდ
                        უერთდებით ახალ როლს, პაროლის შეცვლა არ სჭირდება.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">სახელი და გვარი</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">პაროლი</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                გაიმეორეთ პაროლი
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>
                    </>
                )}

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <Spinner />}
                    მოწვევის მიღება
                </Button>
            </form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'მოწვევის მიღება',
    description: '',
};
