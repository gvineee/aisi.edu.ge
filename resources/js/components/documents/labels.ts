export const documentStatusLabels: Record<string, string> = {
    draft: 'მონახაზი',
    in_review: 'განხილვაში',
    changes_requested: 'დასაზუსტებელი',
    approved: 'დამტკიცებული',
    active: 'მოქმედი',
};

export const permissionLabels: Record<string, string> = {
    view: 'ნახვა',
    edit: 'რედაქტირება',
    manage: 'მართვა',
};

export const permissionHints: Record<string, string> = {
    view: 'ხედავს და ჩამოტვირთავს მოქმედ ვერსიას',
    edit: '+ ახალი ვერსია, მონახაზები, მონაცემების შეცვლა',
    manage: '+ წვდომის განაწილება, არქივი, დამტკიცება',
};

export const classificationLabels: Record<string, string> = {
    curriculum: 'სასწავლო გეგმები',
    projects: 'პროექტები და ღონისძიებები',
    policies: 'წესები და პოლიტიკები',
    minutes: 'სხდომები და ოქმები',
    orders: 'ბრძანებები',
    templates: 'შაბლონები',
    staff_personal: 'საკადრო / პირადი',
    restricted: 'შეზღუდული საცავი',
    contracts: 'ხელშეკრულებები',
    internal: 'შიდა დოკუმენტაცია',
};

export const selectClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

/** A text field from FormData ('' or the fallback for a missing/file entry). */
export function formText(form: FormData, name: string, fallback = ''): string {
    const value = form.get(name);

    return typeof value === 'string' ? value : fallback;
}
