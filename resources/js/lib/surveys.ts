export type SurveyQuestion = {
    id?: number;
    type: 'single' | 'multiple' | 'scale' | 'text';
    prompt: string;
    options: string[];
    isRequired: boolean;
};

export type Survey = {
    id: number;
    title: string;
    description: string | null;
    audience: string;
    isAnonymous: boolean;
    status: 'draft' | 'open' | 'closed';
    closesOn: string | null;
    questions: SurveyQuestion[];
};

export const AUDIENCES: Record<string, string> = {
    guardians: 'მშობლები',
    staff: 'პერსონალი',
    students: 'მოსწავლეები',
    everyone: 'ყველა',
};

export const QUESTION_TYPES: Record<SurveyQuestion['type'], string> = {
    single: 'ერთი პასუხი',
    multiple: 'რამდენიმე პასუხი',
    scale: 'შკალა 1–5',
    text: 'თავისუფალი ტექსტი',
};

export const SURVEY_STATUS: Record<
    Survey['status'],
    { label: string; chip: string }
> = {
    draft: { label: 'მონახაზი', chip: 'bg-slate-100 text-slate-700' },
    open: { label: 'მიმდინარე', chip: 'bg-emerald-100 text-emerald-900' },
    closed: { label: 'დასრულებული', chip: 'bg-amber-100 text-amber-900' },
};
