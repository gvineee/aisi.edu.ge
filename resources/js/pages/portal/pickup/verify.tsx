import { Head, router } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { BadgeCheck, ShieldAlert } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, formatDateTime } from '@/lib/assessment';

type Props = {
    result: {
        studentName: string;
        className: string | null;
        releasedTo: string;
        relationship: string | null;
    } | null;
    canOverride: boolean;
    students: { id: number; name: string; className: string | null }[];
    todayEvents: {
        id: number;
        method: string;
        releasedTo: string;
        createdAt: string | null;
    }[];
};

/**
 * Pickup verification desk. The code is checked and consumed on the
 * server in one step; this page only shows the minimal identity needed to
 * hand the child over.
 */
export default function PickupVerify({
    result,
    canOverride,
    students,
    todayEvents,
}: Props) {
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [showOverride, setShowOverride] = useState(false);
    const errorRef = useRef<HTMLParagraphElement>(null);

    const verify = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            '/portal/pickup-desk/verify',
            { code },
            {
                onSuccess: () => {
                    setCode('');
                    setError(null);
                },
                onError: (errors) => {
                    setError(firstError(errors) ?? 'კოდი არასწორია.');
                    requestAnimationFrame(() => errorRef.current?.focus());
                },
            },
        );
    };

    const override = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        router.post('/portal/pickup-desk/override', new FormData(form), {
            onSuccess: () => {
                toast.success('გამონაკლისი გაყვანა ჩაიწერა.');
                form.reset();
                setShowOverride(false);
            },
            onError: (errors) =>
                toast.error(firstError(errors) ?? 'ვერ ჩაიწერა.'),
        });
    };

    return (
        <PortalLayout>
            <Head title="გაყვანის მაგიდა" />
            <h1 className="mb-6 text-2xl">გაყვანის მაგიდა</h1>

            <form
                onSubmit={verify}
                className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200"
            >
                <label
                    htmlFor="pickup-code"
                    className="mb-1 block text-sm font-medium"
                >
                    ერთჯერადი კოდი
                </label>
                <div className="flex flex-wrap gap-2">
                    <Input
                        id="pickup-code"
                        value={code}
                        onChange={(e) => setCode(e.target.value.toUpperCase())}
                        required
                        maxLength={20}
                        autoComplete="off"
                        autoCapitalize="characters"
                        aria-invalid={error ? true : undefined}
                        aria-describedby={
                            error ? 'pickup-code-error' : undefined
                        }
                        className="max-w-xs font-mono text-lg tracking-widest"
                    />
                    <Button type="submit">შემოწმება</Button>
                </div>
                {error && (
                    <p
                        id="pickup-code-error"
                        ref={errorRef}
                        tabIndex={-1}
                        role="alert"
                        className="mt-2 text-sm text-red-700 focus:outline-none"
                    >
                        {error}
                    </p>
                )}
            </form>

            {result && (
                <div
                    role="status"
                    className="mb-6 flex gap-3 rounded-xl bg-emerald-50 p-5 text-emerald-950"
                >
                    <BadgeCheck className="shrink-0" aria-hidden />
                    <div>
                        <p className="text-lg font-semibold">
                            კოდი დადასტურდა — შეგიძლიათ გაატანოთ
                        </p>
                        <p>
                            მოსწავლე: <strong>{result.studentName}</strong>
                            {result.className ? ` · ${result.className}` : ''}
                        </p>
                        <p>
                            მიმყვანი: <strong>{result.releasedTo}</strong>
                            {result.relationship
                                ? ` (${result.relationship})`
                                : ''}
                        </p>
                        <p className="mt-1 text-sm">
                            შეამოწმეთ მიმყვანის პირადობა. კოდი უკვე
                            გამოყენებულია და მეორედ აღარ იმუშავებს.
                        </p>
                    </div>
                </div>
            )}

            {canOverride && (
                <section className="mb-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
                    {showOverride ? (
                        <form onSubmit={override} className="space-y-3">
                            <p className="flex items-center gap-2 font-medium">
                                <ShieldAlert size={18} aria-hidden /> გაყვანა
                                კოდის გარეშე (გამონაკლისი)
                            </p>
                            <div>
                                <label
                                    htmlFor="ov-student"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    მოსწავლე
                                </label>
                                <select
                                    id="ov-student"
                                    name="student_id"
                                    required
                                    className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                                >
                                    {students.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                            {s.className
                                                ? ` · ${s.className}`
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label
                                    htmlFor="ov-to"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    ვის გადაეცა
                                </label>
                                <Input
                                    id="ov-to"
                                    name="released_to"
                                    required
                                    maxLength={255}
                                />
                            </div>
                            <div>
                                <label
                                    htmlFor="ov-reason"
                                    className="mb-1 block text-sm font-medium"
                                >
                                    მიზეზი (აუდიტში ჩაიწერება)
                                </label>
                                <textarea
                                    id="ov-reason"
                                    name="reason"
                                    required
                                    minLength={10}
                                    maxLength={1000}
                                    className="min-h-20 w-full rounded-md border border-slate-300 p-3 text-sm"
                                />
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit">ჩაწერა</Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setShowOverride(false)}
                                >
                                    გაუქმება
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <Button
                            variant="outline"
                            onClick={() => setShowOverride(true)}
                        >
                            გაყვანა კოდის გარეშე
                        </Button>
                    )}
                </section>
            )}

            <section aria-labelledby="today-events">
                <h2 id="today-events" className="mb-3 text-lg">
                    დღევანდელი გაყვანები
                </h2>
                {todayEvents.length === 0 ? (
                    <p className="text-sm text-slate-500">ჯერ არცერთი.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm">
                        {todayEvents.map((e) => (
                            <li
                                key={e.id}
                                className="flex justify-between gap-2 p-3"
                            >
                                <span>
                                    {e.releasedTo}
                                    {e.method === 'override' && (
                                        <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                                            გამონაკლისი
                                        </span>
                                    )}
                                </span>
                                <span className="text-slate-500">
                                    {e.createdAt
                                        ? formatDateTime(e.createdAt)
                                        : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalLayout>
    );
}
