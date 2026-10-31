// Components
import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="პაროლის აღდგენა" />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
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
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="name@example.com"
                                />

                                <InputError
                                    id="email_error"
                                    message={errors.email}
                                />
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    )}
                                    აღდგენის ბმულის გაგზავნა
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="text-muted-foreground space-x-1 text-center text-sm">
                    <span>ან დაბრუნდით</span>
                    <TextLink href={login()}>შესვლის გვერდზე</TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'პაროლის აღდგენა',
    description: 'შეიყვანეთ ელფოსტა და გამოგიგზავნით პაროლის აღდგენის ბმულს',
};
