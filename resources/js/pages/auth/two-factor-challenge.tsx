import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'აღდგენის კოდი',
                description:
                    'ანგარიშზე შესასვლელად შეიყვანეთ ერთ-ერთი სარეზერვო აღდგენის კოდი.',
                toggleText: 'ავთენტიფიკატორის კოდით შესვლა',
            };
        }

        return {
            title: 'დადასტურების კოდი',
            description:
                'შეიყვანეთ 6-ციფრიანი კოდი ავთენტიფიკატორის აპლიკაციიდან.',
            toggleText: 'აღდგენის კოდით შესვლა',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title="ორფაქტორიანი შემოწმება" />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <>
                                    <label
                                        htmlFor="recovery_code"
                                        className="text-sm font-medium"
                                    >
                                        აღდგენის კოდი
                                    </label>
                                    <Input
                                        id="recovery_code"
                                        name="recovery_code"
                                        type="text"
                                        autoComplete="one-time-code"
                                        placeholder="მაგ. abcd-efgh"
                                        autoFocus={showRecoveryInput}
                                        required
                                        aria-invalid={!!errors.recovery_code}
                                        aria-describedby={
                                            errors.recovery_code
                                                ? 'recovery_code_error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="recovery_code_error"
                                        message={errors.recovery_code}
                                    />
                                </>
                            ) : (
                                <div className="flex flex-col items-center justify-center space-y-3 text-center">
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            autoFocus
                                            aria-label="6-ციფრიანი დადასტურების კოდი"
                                            aria-invalid={!!errors.code}
                                            aria-describedby={
                                                errors.code
                                                    ? 'code_error'
                                                    : undefined
                                            }
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError
                                        id="code_error"
                                        message={errors.code}
                                    />
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                გაგრძელება
                            </Button>

                            <div className="text-muted-foreground text-center text-sm">
                                <span>ან </span>
                                <button
                                    type="button"
                                    className="text-foreground cursor-pointer underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {authConfigContent.toggleText}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
