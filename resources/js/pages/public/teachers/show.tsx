import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PublicLayout from '@/layouts/public/public-layout';

type Teacher = {
    name: string;
    subject: string;
    bio: string | null;
    photoUrl: string | null;
};

type Props = {
    teacher: Teacher;
};

export default function TeacherShow({ teacher }: Props) {
    return (
        <PublicLayout>
            <Head title={teacher.name} />

            <section className="mx-auto max-w-3xl px-6 py-16 sm:py-24">
                <Link
                    href="/teachers"
                    className="mb-8 inline-flex items-center gap-1 text-sm font-semibold text-[var(--brand-primary)]"
                >
                    <ArrowLeft size={16} /> ყველა მასწავლებელი
                </Link>

                <div className="flex flex-col items-center gap-6 text-center sm:flex-row sm:text-left">
                    {teacher.photoUrl ? (
                        <img
                            src={teacher.photoUrl}
                            alt={teacher.name}
                            className="h-32 w-32 shrink-0 rounded-full object-cover"
                        />
                    ) : (
                        <span className="flex h-32 w-32 shrink-0 items-center justify-center rounded-full bg-slate-100 text-4xl font-semibold text-slate-500">
                            {teacher.name.charAt(0)}
                        </span>
                    )}
                    <div>
                        <h1 className="text-2xl sm:text-3xl">
                            {teacher.name}
                        </h1>
                        <p className="mt-1 text-slate-500">
                            {teacher.subject}
                        </p>
                    </div>
                </div>

                {teacher.bio && (
                    <p className="mt-10 text-lg leading-relaxed text-slate-700">
                        {teacher.bio}
                    </p>
                )}
            </section>
        </PublicLayout>
    );
}
