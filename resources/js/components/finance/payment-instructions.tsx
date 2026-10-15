import { Landmark } from 'lucide-react';
import type { PaymentInfo } from '@/lib/finance';

/**
 * How a family pays while there is no online checkout: the school's own
 * bank details plus the payment purpose to write on the transfer.
 */
export default function PaymentInstructions({
    info,
    purpose,
}: {
    info: PaymentInfo;
    purpose?: string;
}) {
    if (!info || (info.bankAccounts.length === 0 && !info.instructions)) {
        return (
            <p className="mb-6 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200">
                გადახდის რეკვიზიტებს სკოლის ბუღალტერია მოგაწვდით.
                ჩარიცხული თანხა ანგარიშზე ბუღალტრის დადასტურების შემდეგ
                აისახება.
            </p>
        );
    }

    return (
        <section
            aria-labelledby="pay-how"
            className="mb-6 rounded-xl bg-white p-5 text-sm ring-1 ring-slate-200"
        >
            <h2 id="pay-how" className="mb-2 flex items-center gap-2 text-lg">
                <Landmark size={18} aria-hidden /> როგორ გადავიხადო
            </h2>
            {info.recipientName && (
                <p>
                    მიმღები: <strong>{info.recipientName}</strong>
                    {info.recipientCode ? ` (ს/კ ${info.recipientCode})` : ''}
                </p>
            )}
            <ul className="my-2 space-y-1">
                {info.bankAccounts.map((account) => (
                    <li key={account.iban}>
                        {account.bank}:{' '}
                        <span className="font-mono break-all select-all">
                            {account.iban}
                        </span>
                    </li>
                ))}
            </ul>
            {purpose && (
                <p>
                    დანიშნულება: <strong>{purpose}</strong>
                </p>
            )}
            {info.instructions && (
                <p className="mt-2 whitespace-pre-line text-slate-600">
                    {info.instructions}
                </p>
            )}
            <p className="mt-2 text-slate-500">
                ჩარიცხვა პორტალში ბუღალტრის მიერ დადასტურების შემდეგ
                გამოჩნდება.
            </p>
        </section>
    );
}
