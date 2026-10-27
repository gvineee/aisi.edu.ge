import { Head, Link } from '@inertiajs/react';
import { Plus, Vote } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { formatDay } from '@/lib/assessment';
import { AUDIENCES, SURVEY_STATUS, type Survey } from '@/lib/surveys';

type Row = {
    id: number;
    title: string;
    audience: string;
    status: Survey['status'];
    isAnonymous: boolean;
    closesOn: string | null;
    accepting: boolean;
    responses: number | null;
    audienceSize: number | null;
    answered: boolean;
};

/**
 * Management: every survey with its response count. Everyone else: the
 * open surveys meant for them, and whether they have answered.
 */
export default function SurveysIndex({
    isManager,
    surveys,
}: {
    isManager: boolean;
    surveys: Row[];
}) {
    return (
        <PortalLayout>
            <Head title="გამოკითხვები" />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-2xl">გამოკითხვები</h1>
                {isManager && (
                    <Button asChild>
                        <Link href="/portal/surveys/create">
                            <Plus className="mr-2 h-4 w-4" /> ახალი გამოკითხვა
                        </Link>
                    </Button>
                )}
            </div>

            {surveys.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <Vote className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">
                        {isManager
                            ? 'გამოკითხვა ჯერ არ შექმნილა.'
                            : 'ახლა თქვენთვის გამოკითხვა არ არის.'}
                    </p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                    {surveys.map((survey) => (
                        <li key={survey.id}>
                            <Link
                                href={`/portal/surveys/${survey.id}`}
                                className="flex min-h-11 flex-wrap items-center justify-between gap-3 p-4 hover:bg-slate-50"
                            >
                                <span>
                                    <span className="block font-medium">
                                        {survey.title}
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        {AUDIENCES[survey.audience] ??
                                            survey.audience}
                                        {survey.isAnonymous
                                            ? ' · ანონიმური'
                                            : ' · სახელით'}
                                        {survey.closesOn &&
                                            ` · ${formatDay(survey.closesOn)}-მდე`}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2 text-xs">
                                    {isManager && survey.responses !== null && (
                                        <span className="text-slate-600">
                                            {survey.responses} /{' '}
                                            {survey.audienceSize} პასუხი
                                        </span>
                                    )}
                                    {!isManager && (
                                        <span
                                            className={`rounded-full px-2 py-0.5 ${survey.answered ? 'bg-emerald-100 text-emerald-900' : 'bg-sky-100 text-sky-900'}`}
                                        >
                                            {survey.answered
                                                ? 'უპასუხეთ'
                                                : survey.accepting
                                                  ? 'პასუხს ელოდება'
                                                  : 'დასრულდა'}
                                        </span>
                                    )}
                                    {isManager && (
                                        <span
                                            className={`rounded-full px-2 py-0.5 ${SURVEY_STATUS[survey.status].chip}`}
                                        >
                                            {SURVEY_STATUS[survey.status].label}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
