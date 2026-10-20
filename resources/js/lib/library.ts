export const REQUIREMENT_LABELS: Record<string, string> = {
    required: 'სავალდებულო',
    optional: 'დამატებითი',
};

export const ACQUISITION_LABELS: Record<string, string> = {
    purchase: 'შესაძენი',
    loan: 'ბიბლიოთეკიდან',
    digital: 'ციფრული წვდომა',
};

export const COPY_STATUS_LABELS: Record<string, string> = {
    available: 'თაროზე',
    on_loan: 'გაცემული',
    on_hold: 'გადადებული ჯავშნისთვის',
    lost: 'დაკარგული',
    withdrawn: 'ჩამოწერილი',
};

export const SCOPE_LABELS: Record<string, string> = {
    catalog_only: 'მხოლოდ კატალოგი',
    loan: 'გაიცემა',
    digital: 'ციფრული',
};

export type BookListItemRow = {
    id: number;
    title: string;
    author: string | null;
    edition: string | null;
    isbn: string | null;
    subject: string | null;
    requirement: string;
    acquisition: string;
    note: string | null;
    resourceId: number | null;
};

/** "2026-10-20" → "20.10.2026" without timezone shifts. */
export function formatDate(isoDate: string): string {
    const [y, m, d] = isoDate.slice(0, 10).split('-');

    return `${d}.${m}.${y}`;
}
