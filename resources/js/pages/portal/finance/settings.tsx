import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import FinanceNav from '@/components/finance/finance-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError } from '@/lib/assessment';
import type { PaymentInfo } from '@/lib/finance';

/**
 * The school's payment details shown to families on the finance page and
 * on invoice PDFs. Nothing is pre-filled: the school enters its own.
 */
export default function FinanceSettings({ settings }: { settings: PaymentInfo }) {
    const [accounts, setAccounts] = useState(
        settings?.bankAccounts.length ? settings.bankAccounts : [{ bank: '', iban: '' }],
    );

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        router.put(
            '/portal/finance/settings',
            {
                recipient_name: form.get('recipient_name') as string,
                recipient_code: form.get('recipient_code') as string,
                instructions: form.get('instructions') as string,
                bank_accounts: accounts.filter((a) => a.bank.trim() !== '' || a.iban.trim() !== ''),
            },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('რეკვიზიტები შენახულია.'),
                onError: (errors) => toast.error(firstError(errors) ?? 'ვერ შეინახა.'),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="გადახდის რეკვიზიტები" />
            <h1 className="mb-4 text-2xl">გადახდის რეკვიზიტები</h1>
            <FinanceNav current="/portal/finance/settings" />

            <form onSubmit={submit} className="max-w-2xl space-y-4 rounded-xl bg-white p-5 ring-1 ring-slate-200">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label htmlFor="s-name" className="mb-1 block text-sm font-medium">
                            მიმღების დასახელება
                        </label>
                        <Input id="s-name" name="recipient_name" defaultValue={settings?.recipientName ?? ''} maxLength={255} />
                    </div>
                    <div>
                        <label htmlFor="s-code" className="mb-1 block text-sm font-medium">
                            საიდენტიფიკაციო კოდი
                        </label>
                        <Input id="s-code" name="recipient_code" defaultValue={settings?.recipientCode ?? ''} maxLength={32} />
                    </div>
                </div>
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">საბანკო ანგარიშები</legend>
                    {accounts.map((account, i) => (
                        <div key={i} className="grid gap-2 sm:grid-cols-[1fr_2fr]">
                            <div>
                                <label htmlFor={`s-bank-${i}`} className="sr-only">
                                    ბანკი {i + 1}
                                </label>
                                <Input
                                    id={`s-bank-${i}`}
                                    value={account.bank}
                                    placeholder="ბანკი"
                                    onChange={(e) => setAccounts((all) => all.map((a, j) => (j === i ? { ...a, bank: e.target.value } : a)))}
                                />
                            </div>
                            <div>
                                <label htmlFor={`s-iban-${i}`} className="sr-only">
                                    IBAN {i + 1}
                                </label>
                                <Input
                                    id={`s-iban-${i}`}
                                    value={account.iban}
                                    placeholder="GE00XX0000000000000000"
                                    autoComplete="off"
                                    className="font-mono"
                                    onChange={(e) => setAccounts((all) => all.map((a, j) => (j === i ? { ...a, iban: e.target.value } : a)))}
                                />
                            </div>
                        </div>
                    ))}
                    <div className="flex gap-2">
                        {accounts.length < 5 && (
                            <Button type="button" size="sm" variant="outline" onClick={() => setAccounts((all) => [...all, { bank: '', iban: '' }])}>
                                + ანგარიში
                            </Button>
                        )}
                        {accounts.length > 1 && (
                            <Button type="button" size="sm" variant="outline" onClick={() => setAccounts((all) => all.slice(0, -1))}>
                                ბოლოს წაშლა
                            </Button>
                        )}
                    </div>
                </fieldset>
                <div>
                    <label htmlFor="s-instr" className="mb-1 block text-sm font-medium">
                        დამატებითი ინსტრუქცია მშობლებისთვის
                    </label>
                    <textarea
                        id="s-instr"
                        name="instructions"
                        defaultValue={settings?.instructions ?? ''}
                        maxLength={2000}
                        rows={4}
                        className="w-full rounded-md border border-slate-300 p-3 text-sm"
                    />
                </div>
                <Button type="submit">შენახვა</Button>
            </form>
        </PortalLayout>
    );
}
