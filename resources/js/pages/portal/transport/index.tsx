import { Head } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Bus, Info } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postWithToast } from '@/lib/operations';

type Route = {
    id: number;
    name: string;
    direction: 'morning' | 'afternoon';
    vehicleLabel: string | null;
    todayStatus: string;
    todayNote: string | null;
    stops: { id: number; name: string; plannedTime: string }[];
};

type Props = {
    isManagement: boolean;
    routes: Route[];
    children: {
        id: number;
        name: string;
        assignments: {
            routeId: number;
            routeName: string;
            stopName: string;
            plannedTime: string;
        }[];
    }[];
    students: { id: number; name: string }[];
    assignedCounts: Record<string, number>;
};

const STATUS: Record<string, string> = {
    planned: 'დაგეგმილი',
    departed: 'გავიდა',
    delayed: 'იგვიანებს',
    completed: 'დასრულდა',
    cancelled: 'გაუქმდა',
};

const DIRECTION = { morning: 'დილის', afternoon: 'საღამოს' } as const;

const submit =
    (url: string, success: string) => (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        postWithToast(url, new FormData(form), success, () => form.reset());
    };

/**
 * School transport: planned routes and the status staff report for today.
 * No live map — without a GPS provider the page never claims a location.
 */
export default function TransportIndex({
    isManagement,
    routes,
    children,
    students,
    assignedCounts,
}: Props) {
    const routeById = new Map(routes.map((r) => [r.id, r]));

    return (
        <PortalLayout>
            <Head title="ტრანსპორტი" />
            <h1 className="mb-2 text-2xl">ტრანსპორტი</h1>
            <p className="mb-6 flex items-center gap-2 text-sm text-slate-600">
                <Info size={16} aria-hidden /> ნაჩვენებია დაგეგმილი დრო და
                სკოლის მიერ მითითებული სტატუსი; ავტობუსის ცოცხალი მდებარეობა არ
                ჩანს.
            </p>

            {children.length > 0 && (
                <section
                    className="mb-8 space-y-4"
                    aria-label="ჩემი შვილების მარშრუტები"
                >
                    {children.map((child) => (
                        <div
                            key={child.id}
                            className="rounded-xl bg-white p-5 ring-1 ring-slate-200"
                        >
                            <h2 className="mb-2 text-lg">{child.name}</h2>
                            {child.assignments.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    მარშრუტი მინიჭებული არ არის.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {child.assignments.map((a) => {
                                        const route = routeById.get(a.routeId);

                                        return (
                                            <li
                                                key={a.routeId}
                                                className="rounded-lg bg-slate-50 p-3"
                                            >
                                                <strong>{a.routeName}</strong> ·
                                                გაჩერება „{a.stopName}“ ·{' '}
                                                {a.plannedTime}
                                                {route && (
                                                    <span className="block text-slate-600">
                                                        დღეს:{' '}
                                                        {STATUS[
                                                            route.todayStatus
                                                        ] ?? route.todayStatus}
                                                        {route.todayNote
                                                            ? ` — ${route.todayNote}`
                                                            : ''}
                                                    </span>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                    ))}
                </section>
            )}

            {isManagement && (
                <section aria-labelledby="routes-admin">
                    <h2 id="routes-admin" className="mb-3 text-lg">
                        მარშრუტები
                    </h2>
                    <form
                        onSubmit={submit(
                            '/portal/transport/routes',
                            'მარშრუტი დაემატა.',
                        )}
                        className="mb-4 grid gap-3 rounded-xl bg-white p-5 ring-1 ring-slate-200 sm:grid-cols-4 sm:items-end"
                    >
                        <div className="sm:col-span-2">
                            <label
                                htmlFor="route-name"
                                className="mb-1 block text-sm font-medium"
                            >
                                დასახელება
                            </label>
                            <Input
                                id="route-name"
                                name="name"
                                required
                                maxLength={255}
                                placeholder="მაგ. მარშრუტი 03"
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="route-dir"
                                className="mb-1 block text-sm font-medium"
                            >
                                მიმართულება
                            </label>
                            <select
                                id="route-dir"
                                name="direction"
                                className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                <option value="morning">დილის</option>
                                <option value="afternoon">საღამოს</option>
                            </select>
                        </div>
                        <Button type="submit">დამატება</Button>
                    </form>

                    {routes.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                            <Bus className="mx-auto mb-3 h-10 w-10 text-slate-400" />
                            <p className="font-medium">
                                მარშრუტი ჯერ არ დამატებულა.
                            </p>
                        </div>
                    ) : (
                        <ul className="space-y-4">
                            {routes.map((route) => (
                                <li
                                    key={route.id}
                                    className="space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-semibold">
                                            {route.name} ·{' '}
                                            {DIRECTION[route.direction]} ·{' '}
                                            {assignedCounts[route.id] ?? 0}{' '}
                                            მოსწავლე
                                        </p>
                                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs">
                                            დღეს: {STATUS[route.todayStatus]}
                                        </span>
                                    </div>
                                    <ol className="text-sm text-slate-600">
                                        {route.stops.map((s) => (
                                            <li key={s.id}>
                                                {s.plannedTime} — {s.name}
                                            </li>
                                        ))}
                                    </ol>
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <form
                                            onSubmit={submit(
                                                `/portal/transport/routes/${route.id}/stops`,
                                                'გაჩერება დაემატა.',
                                            )}
                                            className="flex flex-wrap items-end gap-2"
                                        >
                                            <Input
                                                aria-label="გაჩერება"
                                                name="name"
                                                required
                                                placeholder="გაჩერება"
                                                className="flex-1"
                                            />
                                            <Input
                                                aria-label="დრო"
                                                name="planned_time"
                                                type="time"
                                                required
                                                className="w-28"
                                            />
                                            <Button size="sm" type="submit">
                                                +
                                            </Button>
                                        </form>
                                        <form
                                            onSubmit={submit(
                                                `/portal/transport/routes/${route.id}/assign`,
                                                'მოსწავლე მიენიჭა.',
                                            )}
                                            className="flex flex-wrap items-end gap-2"
                                        >
                                            <select
                                                aria-label="მოსწავლე"
                                                name="student_id"
                                                required
                                                className="h-11 flex-1 rounded-md border border-slate-300 bg-white px-2 text-sm"
                                            >
                                                {students.map((s) => (
                                                    <option
                                                        key={s.id}
                                                        value={s.id}
                                                    >
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <select
                                                aria-label="გაჩერება"
                                                name="transport_stop_id"
                                                required
                                                className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                                            >
                                                {route.stops.map((s) => (
                                                    <option
                                                        key={s.id}
                                                        value={s.id}
                                                    >
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <Button
                                                size="sm"
                                                type="submit"
                                                disabled={
                                                    route.stops.length === 0
                                                }
                                            >
                                                მინიჭება
                                            </Button>
                                        </form>
                                        <form
                                            onSubmit={submit(
                                                `/portal/transport/routes/${route.id}/status`,
                                                'სტატუსი განახლდა.',
                                            )}
                                            className="flex flex-wrap items-end gap-2"
                                        >
                                            <select
                                                aria-label="დღის სტატუსი"
                                                name="status"
                                                defaultValue={route.todayStatus}
                                                className="h-11 rounded-md border border-slate-300 bg-white px-2 text-sm"
                                            >
                                                {Object.entries(STATUS).map(
                                                    ([v, l]) => (
                                                        <option
                                                            key={v}
                                                            value={v}
                                                        >
                                                            {l}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <Input
                                                aria-label="შენიშვნა"
                                                name="note"
                                                placeholder="შენიშვნა"
                                                className="flex-1"
                                                maxLength={255}
                                            />
                                            <Button size="sm" type="submit">
                                                განახლება
                                            </Button>
                                        </form>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </PortalLayout>
    );
}
