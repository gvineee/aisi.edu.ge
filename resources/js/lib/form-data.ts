/** A FormData field as text ('' for a missing field or a file). */
export function fieldText(value: FormDataEntryValue | null): string {
    return typeof value === 'string' ? value : '';
}
