import { Head, Link, router } from '@inertiajs/react';
import { GraduationCap, Plus } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';

type TeacherSummary = {
    id: number;
    slug: string;
    name: string;
    subject: string;
    photoUrl: string | null;
    status: string;
};

type Props = {
    teachers: TeacherSummary[];
};

export default function TeachersIndex({ teachers }: Props) {
    const togglePublish = (teacher: TeacherSummary) => {
        const action = teacher.status === 'published' ? 'unpublish' : 'publish';

        router.post(
            `/portal/teachers/${teacher.id}/${action}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        action === 'publish'
                            ? 'გამოქვეყნდა.'
                            : 'მოხსნილია გამოქვეყნებიდან.',
                    ),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="მასწავლებლები" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">მასწავლებლები</h1>
                <Link href="/portal/teachers/create">
                    <Button>
                        <Plus size={16} /> ახალი მასწავლებელი
                    </Button>
                </Link>
            </div>

            {teachers.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <GraduationCap className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        ჯერ არცერთი მასწავლებელი არ არის დამატებული.
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {teachers.map((teacher) => (
                        <li
                            key={teacher.id}
                            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <Link
                                href={`/portal/teachers/${teacher.id}/edit`}
                                className="flex min-w-0 items-center gap-3"
                            >
                                {teacher.photoUrl ? (
                                    <img
                                        src={teacher.photoUrl}
                                        alt=""
                                        className="h-11 w-11 shrink-0 rounded-full object-cover"
                                    />
                                ) : (
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">
                                        {teacher.name.charAt(0)}
                                    </span>
                                )}
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">
                                        {teacher.name}
                                    </span>
                                    <span className="block truncate text-sm text-slate-500">
                                        {teacher.subject}
                                    </span>
                                </span>
                            </Link>
                            <div className="flex items-center gap-3">
                                <span
                                    className={`inline-flex w-fit rounded-full px-3 py-1 text-xs font-medium ${
                                        teacher.status === 'published'
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {teacher.status === 'published'
                                        ? 'გამოქვეყნებული'
                                        : 'მონახაზი'}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => togglePublish(teacher)}
                                >
                                    {teacher.status === 'published'
                                        ? 'მოხსნა'
                                        : 'გამოქვეყნება'}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
