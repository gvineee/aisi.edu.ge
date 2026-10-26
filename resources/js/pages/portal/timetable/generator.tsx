import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Wand2 } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    years: { id: number; name: string; startsOn: string; endsOn: string }[];
    yearId: number | null;
    periods: number;
    units: number;
    hours: number;
    unassigned: number;
    classes: {
        name: string;
        hours: number;
        groupHours: number;
        capacity: number;
    }[];
    result: {
        versionId: number;
        placed: number;
        unplaced: {
            subject: string;
            classes: string;
            teacher: string;
            missing: number;
        }[];
    } | null;
};

/**
 * Generates a draft timetable from the year's workloads. The result never
 * replaces the timetable in use: it is checked in the grid view and
 * activated by hand.
 */
export default function TimetableGenerator({
    years,
    yearId,
    periods,
    units,
    hours,
    unassigned,
    classes,
    result,
}: Props) {
    const [running, setRunning] = useState(false);
    const year = years.find((item) => item.id === yearId);
    const overfull = classes.filter((item) => item.hours > item.capacity);

    const generate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setRunning(true);
        router.post(
            '/portal/timetable/generator',
            new FormData(event.currentTarget),
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.',
                    ),
                onFinish: () => setRunning(false),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="ცხრილის გენერირება" />
            <div className="mb-2 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">ცხრილის გენერირება</h1>
                <Link
                    href="/portal/timetable"
                    className="min-h-11 text-sm underline-offset-4 hover:underline"
                >
                    ← განრიგის მართვა
                </Link>
            </div>
            <p className="mb-6 max-w-2xl text-sm text-slate-600">
                ცხრილი იქმნება მასწავლებლების დატვირთვიდან: ვინ, რომელ კლასს ან
                ჯგუფს, რა საგანს და რამდენ საათს კვირაში ასწავლის. მასწავლებელი,
                კლასი და ჯგუფი ერთდროულად ორ ადგილას არ მოხვდება. საგანი კვირაში
                თანაბრად ნაწილდება, დღე დილით იწყება და უმიზეზო „ფანჯრებს“ არ
                ტოვებს. შედეგი მონახაზია: მოქმედ ცხრილს არ ცვლის, სანამ ხელით არ
                გააქტიურებთ.
            </p>

            {years.length > 1 && (
                <div className="mb-6 max-w-xs">
                    <label
                        htmlFor="year"
                        className="mb-1 block text-sm font-medium"
                    >
                        სასწავლო წელი
                    </label>
                    <select
                        id="year"
                        value={yearId ?? ''}
                        onChange={(event) =>
                            router.get('/portal/timetable/generator', {
                                year: event.target.value,
                            })
                        }
                        className="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                    >
                        {years.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">დატვირთვა</dt>
                    <dd className="text-2xl font-semibold">{units}</dd>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">
                        გაკვეთილი კვირაში
                    </dt>
                    <dd className="text-2xl font-semibold">{hours}</dd>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">ზარი დღეში</dt>
                    <dd className="text-2xl font-semibold">{periods}</dd>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <dt className="text-xs text-slate-500">
                        მასწავლებლის გარეშე
                    </dt>
                    <dd className="text-2xl font-semibold">{unassigned}</dd>
                </div>
            </dl>

            {overfull.length > 0 && (
                <p
                    role="alert"
                    className="mb-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-900"
                >
                    ამ კლასების მთელი კლასის საათები კვირაში არ ეტევა ({periods}{' '}
                    ზარი × 5 დღე):{' '}
                    {overfull
                        .map((item) => `${item.name} (${item.hours})`)
                        .join(', ')}
                    .
                </p>
            )}

            {result && (
                <section
                    className="mb-8 rounded-xl border border-slate-200 bg-white p-6"
                    aria-labelledby="result"
                >
                    <h2 id="result" className="mb-2 text-lg">
                        შედეგი
                    </h2>
                    <p className="mb-3 text-sm">
                        განთავსდა {result.placed} გაკვეთილი.{' '}
                        <Link
                            href={`/portal/timetable/grid?version=${result.versionId}`}
                            className="font-medium underline underline-offset-4"
                        >
                            ცხრილის ნახვა
                        </Link>
                    </p>
                    {result.unplaced.length > 0 && (
                        <>
                            <p className="mb-2 text-sm text-amber-900">
                                ვერ განთავსდა. ხელით დაამატეთ ან შეცვალეთ
                                დატვირთვა:
                            </p>
                            <ul className="space-y-1 text-sm">
                                {result.unplaced.map((item, index) => (
                                    <li key={index}>
                                        {item.subject} · {item.classes} ·{' '}
                                        {item.teacher} —{' '}
                                        <span className="font-medium">
                                            {item.missing} საათი
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </section>
            )}

            {year && (
                <form
                    onSubmit={generate}
                    className="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-2 lg:grid-cols-4 lg:items-end"
                >
                    <input
                        type="hidden"
                        name="academic_year_id"
                        value={year.id}
                    />
                    <div className="lg:col-span-2">
                        <label
                            htmlFor="label"
                            className="mb-1 block text-sm font-medium"
                        >
                            ვერსიის სახელი
                        </label>
                        <Input
                            id="label"
                            name="label"
                            required
                            maxLength={120}
                            defaultValue={`გენერირებული — ${year.name}`}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="valid_from"
                            className="mb-1 block text-sm font-medium"
                        >
                            მოქმედებს დან
                        </label>
                        <Input
                            id="valid_from"
                            name="valid_from"
                            type="date"
                            required
                            min={year.startsOn}
                            max={year.endsOn}
                            defaultValue={year.startsOn}
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="periods_per_day"
                            className="mb-1 block text-sm font-medium"
                        >
                            მაქს. ზარი დღეში
                        </label>
                        <Input
                            id="periods_per_day"
                            name="periods_per_day"
                            type="number"
                            min={1}
                            max={periods || 15}
                            defaultValue={periods || undefined}
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={running || units === 0 || periods === 0}
                        className="sm:col-span-2 lg:col-span-4"
                    >
                        <Wand2 className="mr-2 h-4 w-4" />{' '}
                        {running ? 'მიმდინარეობს…' : 'ცხრილის გენერირება'}
                    </Button>
                </form>
            )}
        </PortalLayout>
    );
}
