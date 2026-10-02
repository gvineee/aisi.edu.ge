import { Head } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Plus } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from '@/lib/assessment';
import { postWithToast } from '@/lib/operations';

type Competency = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    levels: {
        id: number;
        level: number;
        label: string;
        descriptor: string | null;
    }[];
};

type Props = {
    isManagement: boolean;
    competencies: Competency[];
    teacherAssignments: {
        id: number;
        label: string;
        students: { id: number; name: string }[];
    }[];
    recent: {
        id: number;
        studentName: string;
        competency: string;
        level: string;
        observedOn: string;
        shared: boolean;
    }[];
};

/**
 * Competency framework (management) and observations (teachers, own
 * classes only). Nothing is pre-filled: the framework is the school's.
 */
export default function CompetenciesIndex({
    isManagement,
    competencies,
    teacherAssignments,
    recent,
}: Props) {
    const [levels, setLevels] = useState([
        { label: '' },
        { label: '' },
        { label: '' },
    ]);
    const [taId, setTaId] = useState<number | null>(
        teacherAssignments[0]?.id ?? null,
    );
    const [competencyId, setCompetencyId] = useState<number | null>(
        competencies[0]?.id ?? null,
    );
    const ta = teacherAssignments.find((t) => t.id === taId);
    const competency = competencies.find((c) => c.id === competencyId);

    const create = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        postWithToast(
            '/portal/competencies',
            {
                code: form.get('code'),
                name: form.get('name'),
                description: form.get('description'),
                levels,
            },
            'კომპეტენცია დაემატა.',
        );
    };

    const observe = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        postWithToast(
            '/portal/competencies/observations',
            new FormData(form),
            'დაკვირვება ჩაიწერა.',
            () => form.reset(),
        );
    };

    return (
        <PortalLayout>
            <Head title="კომპეტენციები" />
            <h1 className="mb-6 text-2xl">კომპეტენციები</h1>

            {isManagement && (
                <form
                    onSubmit={create}
                    className="mb-8 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                >
                    <h2 className="text-lg">ახალი კომპეტენცია</h2>
                    <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <Input
                            aria-label="კოდი"
                            name="code"
                            required
                            placeholder="კოდი"
                            pattern="[A-Za-z0-9._-]+"
                        />
                        <Input
                            aria-label="დასახელება"
                            name="name"
                            required
                            placeholder="დასახელება"
                        />
                    </div>
                    <textarea
                        aria-label="აღწერა"
                        name="description"
                        className="min-h-16 w-full rounded-md border border-slate-300 p-3 text-sm"
                        placeholder="აღწერა"
                    />
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            დონეები (დაბლიდან მაღლისკენ)
                        </legend>
                        {levels.map((l, i) => (
                            <Input
                                key={i}
                                aria-label={`დონე ${i + 1}`}
                                required
                                value={l.label}
                                onChange={(e) =>
                                    setLevels((all) =>
                                        all.map((x, j) =>
                                            j === i
                                                ? { label: e.target.value }
                                                : x,
                                        ),
                                    )
                                }
                                placeholder={`დონე ${i + 1}`}
                            />
                        ))}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setLevels((all) => [...all, { label: '' }])
                            }
                        >
                            <Plus size={16} /> დონე
                        </Button>
                    </fieldset>
                    <Button type="submit">შენახვა</Button>
                </form>
            )}

            {competencies.length === 0 ? (
                <p className="mb-8 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm">
                    სკოლას კომპეტენციების ჩარჩო ჯერ არ განუსაზღვრავს.
                </p>
            ) : (
                <ul className="mb-8 grid gap-3 md:grid-cols-2">
                    {competencies.map((c) => (
                        <li
                            key={c.id}
                            className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                        >
                            <p className="font-medium">
                                {c.code} · {c.name}
                            </p>
                            <ol className="mt-1 text-sm text-slate-600">
                                {c.levels.map((l) => (
                                    <li key={l.id}>
                                        {l.level}. {l.label}
                                    </li>
                                ))}
                            </ol>
                        </li>
                    ))}
                </ul>
            )}

            {teacherAssignments.length > 0 && competencies.length > 0 && (
                <form
                    onSubmit={observe}
                    className="mb-8 space-y-3 rounded-xl bg-white p-5 ring-1 ring-slate-200"
                >
                    <h2 className="text-lg">დაკვირვების ჩაწერა</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-sm font-medium">
                            კლასი და საგანი
                            <select
                                name="teacher_assignment_id"
                                value={taId ?? ''}
                                onChange={(e) =>
                                    setTaId(Number(e.target.value))
                                }
                                className="mt-1 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {teacherAssignments.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            მოსწავლე
                            <select
                                name="student_id"
                                required
                                className="mt-1 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {ta?.students.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            კომპეტენცია
                            <select
                                value={competencyId ?? ''}
                                onChange={(e) =>
                                    setCompetencyId(Number(e.target.value))
                                }
                                className="mt-1 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {competencies.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            დონე
                            <select
                                name="competency_level_id"
                                required
                                className="mt-1 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm"
                            >
                                {competency?.levels.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.level}. {l.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            თარიღი
                            <Input
                                name="observed_on"
                                type="date"
                                required
                                className="mt-1"
                            />
                        </label>
                    </div>
                    <textarea
                        aria-label="შენიშვნა"
                        name="note"
                        maxLength={2000}
                        className="min-h-16 w-full rounded-md border border-slate-300 p-3 text-sm"
                        placeholder="შენიშვნა / მტკიცებულება"
                    />
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="shared_with_family"
                            value="1"
                            className="h-4 w-4"
                        />{' '}
                        ოჯახს გაუზიარდეს
                    </label>
                    <Button type="submit">ჩაწერა</Button>
                </form>
            )}

            {recent.length > 0 && (
                <section aria-labelledby="recent-obs">
                    <h2 id="recent-obs" className="mb-2 text-lg">
                        ჩემი ბოლო დაკვირვებები
                    </h2>
                    <ul className="space-y-1 text-sm">
                        {recent.map((o) => (
                            <li key={o.id}>
                                {formatDay(o.observedOn)} · {o.studentName} ·{' '}
                                {o.competency}: {o.level}
                                {o.shared ? '' : ' (ოჯახს არ უჩანს)'}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PortalLayout>
    );
}
