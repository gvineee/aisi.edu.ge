import { Link } from '@inertiajs/react';

const ITEMS = [
    { href: '/portal/finance', label: 'ინვოისები' },
    { href: '/portal/finance/payments', label: 'გადახდები' },
    { href: '/portal/finance/debtors', label: 'დავალიანებები' },
    { href: '/portal/finance/fee-plans', label: 'საფასური და ხელშეკრულებები' },
    { href: '/portal/finance/reconciliation', label: 'შეჯერება' },
    { href: '/portal/finance/settings', label: 'რეკვიზიტები' },
];

/**
 * Accountant's section navigation. Scrolls horizontally on its own on
 * narrow screens instead of overflowing the page.
 */
export default function FinanceNav({ current }: { current: string }) {
    return (
        <nav aria-label="ფინანსების განყოფილებები" className="-mx-4 mb-5 overflow-x-auto px-4">
            <ul className="flex min-w-max gap-2">
                {ITEMS.map((item) => {
                    const active = item.href === current;

                    return (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                aria-current={active ? 'page' : undefined}
                                className={`inline-flex min-h-11 items-center rounded-full px-4 text-sm ring-1 ${
                                    active
                                        ? 'bg-slate-900 text-white ring-slate-900'
                                        : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'
                                }`}
                            >
                                {item.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
