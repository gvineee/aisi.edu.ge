import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Users } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type Child = {
    id: number;
    name: string;
    className: string | null;
    permissions: {
        academic: boolean;
        financial: boolean;
        pickup: boolean;
        notifications: boolean;
    };
};

type Props = {
    children: Child[];
};

/**
 * Every value here comes from the authenticated guardian's own active
 * guardian_links (DashboardController) — never a client-supplied child id,
 * and never demo data. An empty list is a real, honest state, not
 * something papered over with placeholders.
 */
export default function ParentDashboard({ children }: Props) {
    const [selectedId, setSelectedId] = useState(children[0]?.id ?? null);
    const selected = children.find((c) => c.id === selectedId) ?? null;

    return (
        <PortalLayout>
            <Head title="ჩემი აისი" />

            <h1 className="mb-2 text-2xl">დღეს</h1>
            <p className="mb-8 text-sm text-slate-500">
                {new Date().toLocaleDateString('ka-GE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                })}
            </p>

            {children.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <Users className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        თქვენს ანგარიშზე ჯერ არცერთი ბავშვი არ არის
                        დაკავშირებული.
                    </p>
                    <p className="mt-2 text-sm text-slate-500">
                        დაუკავშირდით სკოლის ადმინისტრაციას, თუ ეს მოსალოდნელი არ
                        იყო.
                    </p>
                </div>
            ) : (
                <>
                    {children.length > 1 && (
                        <div
                            className="mb-6 flex gap-2"
                            role="tablist"
                            aria-label="ბავშვის არჩევა"
                        >
                            {children.map((child) => (
                                <button
                                    key={child.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={selectedId === child.id}
                                    onClick={() => setSelectedId(child.id)}
                                    className={`min-h-11 rounded-full border px-4 py-2 text-sm font-medium ${
                                        selectedId === child.id
                                            ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-white'
                                            : 'border-slate-300 bg-white'
                                    }`}
                                >
                                    {child.name}
                                </button>
                            ))}
                        </div>
                    )}

                    {selected && (
                        <section className="rounded-xl bg-[var(--brand-primary)] p-8 text-white">
                            <p className="text-xs tracking-wide text-white/70">
                                {selected.className ??
                                    'კლასი მინიჭებული არ არის'}
                            </p>
                            <h2 className="mt-2 text-xl">
                                {selected.name}-ის დღე
                            </h2>
                            <p className="mt-3 max-w-md text-sm text-white/80">
                                განრიგი, დავალებები და დასწრება ჯერ არ არის
                                გააქტიურებული — ეს ფუნქციონალი მომდევნო ეტაპზეა
                                დაგეგმილი.
                            </p>
                            <ul className="mt-6 flex flex-wrap gap-3 text-xs">
                                {selected.permissions.academic && (
                                    <li className="rounded-full bg-white/15 px-3 py-1">
                                        აკადემიური ინფორმაცია
                                    </li>
                                )}
                                {selected.permissions.financial && (
                                    <li className="rounded-full bg-white/15 px-3 py-1">
                                        ფინანსები
                                    </li>
                                )}
                                {selected.permissions.pickup && (
                                    <li className="rounded-full bg-white/15 px-3 py-1">
                                        წაყვანის უფლება
                                    </li>
                                )}
                            </ul>
                        </section>
                    )}
                </>
            )}
        </PortalLayout>
    );
}
