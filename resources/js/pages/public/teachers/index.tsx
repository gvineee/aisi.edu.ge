import { Head, Link } from '@inertiajs/react';
import PublicLayout from '@/layouts/public/public-layout';

type Teacher = {
    slug: string;
    name: string;
    subject: string;
    photoUrl: string | null;
};

type Props = {
    teachers: Teacher[];
};

export default function TeachersIndex({ teachers }: Props) {
    return (
        <PublicLayout>
            <Head title="მასწავლებლები" />

            <section className="mx-auto max-w-6xl px-6 py-16 sm:py-24">
                <p className="mb-4 text-xs font-bold tracking-widest text-slate-500">
                    ჩვენი გუნდი
                </p>
                <h1 className="mb-10 text-3xl sm:text-4xl">
                    გაიცანი ჩვენი მასწავლებლები.
                </h1>

                {teachers.length === 0 ? (
                    <p className="rounded-xl bg-slate-50 p-8 text-center text-slate-500">
                        მასწავლებლების სია მალე გამოქვეყნდება.
                    </p>
                ) : (
                    <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        {teachers.map((teacher) => (
                            <Link
                                key={teacher.slug}
                                href={`/teachers/${teacher.slug}`}
                                className="text-center"
                            >
                                {teacher.photoUrl ? (
                                    <img
                                        src={teacher.photoUrl}
                                        alt={teacher.name}
                                        className="mx-auto mb-4 h-28 w-28 rounded-full object-cover"
                                    />
                                ) : (
                                    <span className="mx-auto mb-4 flex h-28 w-28 items-center justify-center rounded-full bg-slate-100 text-2xl font-semibold text-slate-500">
                                        {teacher.name.charAt(0)}
                                    </span>
                                )}
                                <h3 className="font-semibold">
                                    {teacher.name}
                                </h3>
                                <p className="text-sm text-slate-500">
                                    {teacher.subject}
                                </p>
                            </Link>
                        ))}
                    </div>
                )}
            </section>
        </PublicLayout>
    );
}
