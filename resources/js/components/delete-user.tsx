import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="ანგარიშის წაშლა"
                description="ანგარიშისა და მასთან დაკავშირებული ყველა მონაცემის წაშლა"
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">გაფრთხილება</p>
                    <p className="text-sm">
                        იმოქმედეთ სიფრთხილით — ამ მოქმედების გაუქმება
                        შეუძლებელია.
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            ანგარიშის წაშლა
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            ნამდვილად გსურთ ანგარიშის წაშლა?
                        </DialogTitle>
                        <DialogDescription>
                            ანგარიშის წაშლისას მისი ყველა მონაცემი სამუდამოდ
                            წაიშლება და აღდგენა შეუძლებელი იქნება.
                            დასადასტურებლად შეიყვანეთ პაროლი.
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            პაროლი
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            aria-invalid={!!errors.password}
                                            aria-describedby={
                                                errors.password
                                                    ? 'delete_user_password_error'
                                                    : undefined
                                            }
                                            name="password"
                                            ref={passwordInput}
                                            placeholder="პაროლი"
                                            autoComplete="current-password"
                                        />

                                        <InputError
                                            id="delete_user_password_error"
                                            message={errors.password}
                                        />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                გაუქმება
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                ანგარიშის წაშლა
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
