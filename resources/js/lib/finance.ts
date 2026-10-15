export type PaymentInfo = {
    recipientName: string | null;
    recipientCode: string | null;
    bankAccounts: { bank: string; iban: string }[];
    instructions: string | null;
} | null;

export const INVOICE_STATUS: Record<string, string> = {
    issued: 'გადასახდელი',
    partially_paid: 'ნაწილობრივ გადახდილი',
    paid: 'გადახდილი',
    void: 'გაუქმებული',
};

export const PAYMENT_METHODS: { value: string; label: string }[] = [
    { value: 'bank_transfer', label: 'საბანკო გადარიცხვა' },
    { value: 'cash', label: 'ნაღდი' },
    { value: 'pos', label: 'POS ტერმინალი' },
    { value: 'other', label: 'სხვა' },
];

/** Idempotency key for one form submission (a retry reuses it). */
export function newSubmissionKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    // RFC 4122 v4 fallback for older browsers.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/** Today's date in Tbilisi as YYYY-MM-DD. */
export function todayTbilisi(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Tbilisi',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

export const MONEY_PATTERN = '\\d{1,7}([.,]\\d{1,2})?';
