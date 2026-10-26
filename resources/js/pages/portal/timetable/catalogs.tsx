import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Subject = {
    id: number;
    name: string;
    shortName: string | null;
    uses: number;
};
type Room = { id: number; name: string; uses: number };

type Props = { subjects: Subject[]; rooms: Room[] };

const onError = (errors: Record<string, string>) =>
    toast.error(Object.values(errors)[0] ?? 'შემოწმეთ ფორმის ველები.');

function Row({
    label,
    shortName,
    uses,
    withShort,
    onSave,
    onDelete,
}: {
    label: string;
    shortName?: string | null;
    uses: number;
    withShort: boolean;
    onSave: (name: string, short: string) => void;
    onDelete: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(label);
    const [short, setShort] = useState(shortName ?? '');

    if (editing) {
        return (
            <li className="flex flex-wrap items-end gap-2 px-4 py-3">
                <Input
                    aria-label="დასახელება"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="min-w-[200px] flex-1"
                />
                {withShort && (
                    <Input
                        aria-label="შემოკლება"
                        value={short}
                        onChange={(e) => setShort(e.target.value)}
                        className="w-40"
                    />
                )}
                <Button
                    onClick={() => {
                        onSave(name, short);
                        setEditing(false);
                    }}
                >
                    შენახვა
                </Button>
                <Button variant="ghost" onClick={() => setEditing(false)}>
                    გაუქმება
                </Button>
            </li>
        );
    }

    return (
        <li className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
            <span>
                <span className="font-medium">{label}</span>
                {shortName && (
                    <span className="ml-2 text-slate-500">({shortName})</span>
                )}
                <span className="ml-2 text-xs text-slate-500">
                    {uses > 0 ? `გამოიყენება: ${uses}` : 'არ გამოიყენება'}
                </span>
            </span>
            <span className="flex gap-1">
                <Button variant="ghost" onClick={() => setEditing(true)}>
                    რედაქტირება
                </Button>
                {uses === 0 && (
                    <Button variant="ghost" onClick={onDelete}>
                        წაშლა
                    </Button>
                )}
            </span>
        </li>
    );
}

/**
 * Subjects and rooms used by the timetable, workloads and the timetable
 * generator. Only what nothing uses can be deleted.
 */
export default function TimetableCatalogs({ subjects, rooms }: Props) {
    const options = { preserveScroll: true, onError };

    const add = (url: string) => (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const target = event.currentTarget;
        router.post(url, new FormData(target), {
            ...options,
            onSuccess: () => target.reset(),
        });
    };

    return (
        <PortalLayout>
            <Head title="საგნები და ოთახები" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">საგნები და ოთახები</h1>
                <Link
                    href="/portal/timetable"
                    className="min-h-11 text-sm underline-offset-4 hover:underline"
                >
                    ← განრიგის მართვა
                </Link>
            </div>

            <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
                <section aria-labelledby="subjects">
                    <h2 id="subjects" className="mb-3 text-lg">
                        საგნები ({subjects.length})
                    </h2>
                    <form
                        onSubmit={add('/portal/timetable/catalogs/subjects')}
                        className="mb-3 flex flex-wrap gap-2"
                    >
                        <Input
                            name="name"
                            required
                            maxLength={120}
                            placeholder="ახალი საგანი"
                            aria-label="საგნის დასახელება"
                            className="min-w-[180px] flex-1"
                        />
                        <Input
                            name="short_name"
                            maxLength={40}
                            placeholder="შემოკლება"
                            aria-label="შემოკლება"
                            className="w-36"
                        />
                        <Button type="submit">დამატება</Button>
                    </form>
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {subjects.map((subject) => (
                            <Row
                                key={subject.id}
                                label={subject.name}
                                shortName={subject.shortName}
                                uses={subject.uses}
                                withShort
                                onSave={(name, short) =>
                                    router.put(
                                        `/portal/timetable/catalogs/subjects/${subject.id}`,
                                        { name, short_name: short },
                                        options,
                                    )
                                }
                                onDelete={() =>
                                    router.delete(
                                        `/portal/timetable/catalogs/subjects/${subject.id}`,
                                        options,
                                    )
                                }
                            />
                        ))}
                    </ul>
                </section>

                <section aria-labelledby="rooms">
                    <h2 id="rooms" className="mb-3 text-lg">
                        ოთახები ({rooms.length})
                    </h2>
                    <form
                        onSubmit={add('/portal/timetable/catalogs/rooms')}
                        className="mb-3 flex flex-wrap gap-2"
                    >
                        <Input
                            name="name"
                            required
                            maxLength={120}
                            placeholder="ახალი ოთახი"
                            aria-label="ოთახის დასახელება"
                            className="min-w-[180px] flex-1"
                        />
                        <Button type="submit">დამატება</Button>
                    </form>
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                        {rooms.map((room) => (
                            <Row
                                key={room.id}
                                label={room.name}
                                uses={room.uses}
                                withShort={false}
                                onSave={(name) =>
                                    router.put(
                                        `/portal/timetable/catalogs/rooms/${room.id}`,
                                        { name },
                                        options,
                                    )
                                }
                                onDelete={() =>
                                    router.delete(
                                        `/portal/timetable/catalogs/rooms/${room.id}`,
                                        options,
                                    )
                                }
                            />
                        ))}
                    </ul>
                </section>
            </div>
        </PortalLayout>
    );
}
