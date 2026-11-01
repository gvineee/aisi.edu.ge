import { useId, useState } from 'react';
import { ArrowDown, ArrowUp, Code2, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Visual editor for a CMS page's typed blocks (audit A13): each block type
 * gets labelled form fields, blocks can be added, removed and reordered,
 * and links can be picked from the media library. The result is the same
 * JSON array the server validates and the public page renders — JSON
 * editing stays available as an advanced mode, never the only way.
 */

type Json = Record<string, unknown>;

type Field =
    | { key: string; label: string; kind: 'text' | 'textarea' | 'link' }
    | {
          key: string;
          label: string;
          kind: 'list';
          itemLabel: string;
          fields: Field[];
      };

type Schema = { label: string; fields: Field[] };

const cta: Field[] = [
    { key: 'ctaLabel', label: 'ღილაკის ტექსტი', kind: 'text' },
    { key: 'ctaHref', label: 'ღილაკის ბმული', kind: 'link' },
];

export const BLOCK_SCHEMAS: Record<string, Schema> = {
    hero: {
        label: 'მთავარი ბანერი',
        fields: [
            { key: 'eyebrow', label: 'ზედა წარწერა', kind: 'text' },
            { key: 'heading', label: 'სათაური', kind: 'text' },
            { key: 'headingLine2', label: 'სათაურის მეორე ხაზი', kind: 'text' },
            {
                key: 'headingEmphasis',
                label: 'გამოკვეთილი სიტყვა (ფერით)',
                kind: 'text',
            },
            { key: 'body', label: 'ტექსტი', kind: 'textarea' },
            { key: 'note', label: 'მცირე შენიშვნა', kind: 'text' },
            { key: 'imageAlt', label: 'სურათის აღწერა (alt)', kind: 'text' },
        ],
    },
    values: {
        label: 'ღირებულებების ზოლი',
        fields: [
            {
                key: 'items',
                label: 'ღირებულებები',
                kind: 'list',
                itemLabel: 'ღირებულება',
                fields: [{ key: 'label', label: 'ტექსტი', kind: 'text' }],
            },
        ],
    },
    history: {
        label: 'სკოლის შესახებ (ისტორია)',
        fields: [
            { key: 'eyebrow', label: 'ზედა წარწერა', kind: 'text' },
            { key: 'heading', label: 'სათაური', kind: 'text' },
            { key: 'lead', label: 'შესავალი წინადადება', kind: 'text' },
            { key: 'body', label: 'ტექსტი', kind: 'textarea' },
            ...cta,
        ],
    },
    programs: {
        label: 'სასწავლო საფეხურები',
        fields: [
            { key: 'heading', label: 'სათაური', kind: 'text' },
            {
                key: 'items',
                label: 'საფეხურები',
                kind: 'list',
                itemLabel: 'საფეხური',
                fields: [
                    { key: 'title', label: 'სახელი', kind: 'text' },
                    { key: 'grade', label: 'საფეხური/კლასები', kind: 'text' },
                    { key: 'body', label: 'აღწერა', kind: 'textarea' },
                    {
                        key: 'href',
                        label: '„გაიგე მეტი“ ბმული (არასავალდებულო)',
                        kind: 'link',
                    },
                ],
            },
        ],
    },
    text: {
        label: 'ტექსტის სექცია',
        fields: [
            { key: 'heading', label: 'სათაური', kind: 'text' },
            {
                key: 'body',
                label: 'ტექსტი (აბზაცები ცარიელი ხაზით)',
                kind: 'textarea',
            },
        ],
    },
    portal_promo: {
        label: 'პორტალის რეკლამა',
        fields: [
            { key: 'eyebrow', label: 'ზედა წარწერა', kind: 'text' },
            { key: 'heading', label: 'სათაური', kind: 'text' },
            { key: 'body', label: 'ტექსტი', kind: 'textarea' },
            ...cta,
        ],
    },
    life: {
        label: 'სასკოლო ცხოვრების ამბები',
        fields: [
            { key: 'eyebrow', label: 'ზედა წარწერა', kind: 'text' },
            { key: 'heading', label: 'სათაური', kind: 'text' },
            ...cta,
        ],
    },
    teachers: {
        label: 'მასწავლებლები',
        fields: [
            { key: 'eyebrow', label: 'ზედა წარწერა', kind: 'text' },
            { key: 'heading', label: 'სათაური', kind: 'text' },
            ...cta,
        ],
    },
    contact_cta: {
        label: 'ვიზიტის მოწვევა',
        fields: [
            { key: 'eyebrow', label: 'ზედა წარწერა', kind: 'text' },
            { key: 'heading', label: 'სათაური', kind: 'text' },
            { key: 'body', label: 'ტექსტი', kind: 'textarea' },
        ],
    },
    documents: {
        label: 'დოკუმენტების სია',
        fields: [
            { key: 'heading', label: 'სათაური', kind: 'text' },
            {
                key: 'groups',
                label: 'ჯგუფები',
                kind: 'list',
                itemLabel: 'ჯგუფი',
                fields: [
                    { key: 'heading', label: 'ჯგუფის სათაური', kind: 'text' },
                    {
                        key: 'items',
                        label: 'დოკუმენტები',
                        kind: 'list',
                        itemLabel: 'დოკუმენტი',
                        fields: [
                            { key: 'title', label: 'სახელი', kind: 'text' },
                            { key: 'url', label: 'ბმული', kind: 'link' },
                        ],
                    },
                ],
            },
        ],
    },
};

export type MediaOption = { id: number; url: string; originalFilename: string };

const inputClass =
    'h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm';

/** A body that is a list of paragraphs is edited as blank-line-separated text. */
function textOf(value: unknown): string {
    if (Array.isArray(value)) return value.join('\n\n');

    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

function FieldInput({
    field,
    value,
    onChange,
    media,
}: {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
    media: MediaOption[];
}) {
    const id = useId();

    if (field.kind === 'list') {
        const items = Array.isArray(value) ? (value as Json[]) : [];
        const set = (next: Json[]) => onChange(next);

        return (
            <fieldset className="space-y-3 rounded-lg border border-slate-200 p-3">
                <legend className="px-1 text-sm font-medium">
                    {field.label}
                </legend>
                {items.map((item, index) => (
                    <div
                        key={index}
                        className="space-y-2 rounded-md bg-slate-50 p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-slate-600">
                                {field.itemLabel} {index + 1}
                            </span>
                            <ItemControls
                                label={`${field.itemLabel} ${index + 1}`}
                                index={index}
                                count={items.length}
                                onMove={(to) => {
                                    const next = [...items];
                                    const [moved] = next.splice(index, 1);
                                    next.splice(to, 0, moved);
                                    set(next);
                                }}
                                onRemove={() =>
                                    set(items.filter((_, i) => i !== index))
                                }
                            />
                        </div>
                        {field.fields.map((sub) => (
                            <FieldInput
                                key={sub.key}
                                field={sub}
                                value={item[sub.key]}
                                media={media}
                                onChange={(v) =>
                                    set(
                                        items.map((it, i) =>
                                            i === index
                                                ? { ...it, [sub.key]: v }
                                                : it,
                                        ),
                                    )
                                }
                            />
                        ))}
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="min-h-11"
                    onClick={() => set([...items, {}])}
                >
                    <Plus size={16} aria-hidden /> {field.itemLabel}ს დამატება
                </Button>
            </fieldset>
        );
    }

    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium">
                {field.label}
            </label>
            {field.kind === 'textarea' ? (
                <textarea
                    id={id}
                    value={textOf(value)}
                    onChange={(event) => {
                        const text = event.target.value;
                        // Keep a paragraph list a list (the public page renders both).
                        onChange(
                            Array.isArray(value) ? text.split(/\n\s*\n/) : text,
                        );
                    }}
                    rows={4}
                    className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                />
            ) : (
                <input
                    id={id}
                    value={textOf(value)}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? undefined
                                : event.target.value,
                        )
                    }
                    inputMode={field.kind === 'link' ? 'url' : undefined}
                    placeholder={
                        field.kind === 'link'
                            ? '/about, https://… ან ფაილი მედიიდან'
                            : undefined
                    }
                    className={inputClass}
                />
            )}
            {field.kind === 'link' && media.length > 0 && (
                <select
                    aria-label={`${field.label}: ფაილის არჩევა მედიიდან`}
                    value=""
                    onChange={(event) =>
                        event.target.value && onChange(event.target.value)
                    }
                    className={`${inputClass} text-slate-600`}
                >
                    <option value="">ან აირჩიეთ ფაილი მედიიდან…</option>
                    {media.map((item) => (
                        <option key={item.id} value={item.url}>
                            {item.originalFilename}
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}

function ItemControls({
    label,
    index,
    count,
    onMove,
    onRemove,
}: {
    label: string;
    index: number;
    count: number;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex gap-1">
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="min-h-11 min-w-11"
                disabled={index === 0}
                onClick={() => onMove(index - 1)}
                aria-label={`${label}: ზემოთ აწევა`}
            >
                <ArrowUp size={16} aria-hidden />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="min-h-11 min-w-11"
                disabled={index === count - 1}
                onClick={() => onMove(index + 1)}
                aria-label={`${label}: ქვემოთ ჩამოწევა`}
            >
                <ArrowDown size={16} aria-hidden />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="min-h-11 min-w-11 text-red-700"
                onClick={onRemove}
                aria-label={`${label}: წაშლა`}
            >
                <Trash2 size={16} aria-hidden />
            </Button>
        </div>
    );
}

/** Drops empty optional fields so the JSON stays as tidy as hand-written. */
function clean(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(clean);
    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value as Json)
                .filter(([, v]) => v !== undefined && v !== '')
                .map(([k, v]) => [k, clean(v)]),
        );
    }

    return value;
}

export default function BlockEditor({
    initial,
    media,
    error,
}: {
    initial: Json[];
    media: MediaOption[];
    error?: string;
}) {
    const [blocks, setBlocks] = useState<Json[]>(initial);
    const [advanced, setAdvanced] = useState(false);
    const [json, setJson] = useState('');
    const [jsonError, setJsonError] = useState<string | null>(null);
    const [newType, setNewType] = useState('text');

    const serialized = JSON.stringify(clean(blocks));

    const openAdvanced = () => {
        setJson(JSON.stringify(clean(blocks), null, 2));
        setJsonError(null);
        setAdvanced(true);
    };

    const applyJson = () => {
        try {
            const parsed: unknown = JSON.parse(json);
            if (
                !Array.isArray(parsed) ||
                parsed.some(
                    (b) =>
                        !b ||
                        typeof b !== 'object' ||
                        typeof (b as Json).type !== 'string',
                )
            ) {
                throw new Error('shape');
            }
            setBlocks(parsed as Json[]);
            setAdvanced(false);
        } catch {
            setJsonError(
                'JSON არასწორია: საჭიროა მასივი, სადაც ყველა ბლოკს აქვს „type“.',
            );
        }
    };

    const update = (index: number, next: Json) =>
        setBlocks(blocks.map((b, i) => (i === index ? next : b)));

    return (
        <fieldset className="space-y-4" aria-describedby="blocks-help">
            <legend className="text-sm font-medium">გვერდის შინაარსი</legend>
            <p id="blocks-help" className="text-xs text-slate-600">
                ბლოკები საჯარო გვერდზე ამავე თანმიმდევრობით გამოჩნდება. HTML და
                სკრიპტი დაუშვებელია; ბმული შეიძლება იყოს საიტის გვერდი (/about),
                https:// მისამართი ან ფაილი მედიიდან.
            </p>
            <input
                type="hidden"
                name="blocks"
                value={advanced ? json : serialized}
            />
            {error && (
                <p role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            )}

            {advanced ? (
                <div className="space-y-2">
                    <label
                        htmlFor="blocks-json"
                        className="block text-sm font-medium"
                    >
                        ბლოკები JSON-ად (მოწინავე რეჟიმი)
                    </label>
                    <textarea
                        id="blocks-json"
                        value={json}
                        onChange={(event) => setJson(event.target.value)}
                        rows={16}
                        aria-invalid={jsonError !== null}
                        aria-describedby={
                            jsonError ? 'blocks-json-error' : undefined
                        }
                        className="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                    />
                    {jsonError && (
                        <p
                            id="blocks-json-error"
                            className="text-sm text-red-700"
                        >
                            {jsonError}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={applyJson}
                        >
                            ფორმებზე დაბრუნება
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setAdvanced(false)}
                        >
                            ცვლილების გაუქმება
                        </Button>
                    </div>
                </div>
            ) : (
                <>
                    {blocks.length === 0 && (
                        <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-600">
                            გვერდზე ჯერ ბლოკი არ არის. დაამატეთ ქვემოთ.
                        </p>
                    )}
                    {blocks.map((block, index) => {
                        const type = String(block.type);
                        const schema = BLOCK_SCHEMAS[type];
                        const label = `${index + 1}. ${schema?.label ?? type}`;

                        return (
                            <section
                                key={index}
                                aria-label={label}
                                className="space-y-3 rounded-xl border border-slate-200 bg-white p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-body text-sm font-semibold">
                                        {label}
                                    </h3>
                                    <ItemControls
                                        label={label}
                                        index={index}
                                        count={blocks.length}
                                        onMove={(to) => {
                                            const next = [...blocks];
                                            const [moved] = next.splice(
                                                index,
                                                1,
                                            );
                                            next.splice(to, 0, moved);
                                            setBlocks(next);
                                        }}
                                        onRemove={() =>
                                            setBlocks(
                                                blocks.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                                {schema ? (
                                    schema.fields.map((field) => (
                                        <FieldInput
                                            key={field.key}
                                            field={field}
                                            value={block[field.key]}
                                            media={media}
                                            onChange={(value) =>
                                                update(index, {
                                                    ...block,
                                                    [field.key]: value,
                                                })
                                            }
                                        />
                                    ))
                                ) : (
                                    <p className="text-sm text-slate-600">
                                        ამ ბლოკს („{type}“) ფორმა არ აქვს — ის
                                        უცვლელად შეინახება; შესაცვლელად
                                        გამოიყენეთ JSON რეჟიმი.
                                    </p>
                                )}
                            </section>
                        );
                    })}

                    <div className="flex flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3">
                        <div className="space-y-1">
                            <label
                                htmlFor="new-block-type"
                                className="block text-sm font-medium"
                            >
                                ახალი ბლოკი
                            </label>
                            <select
                                id="new-block-type"
                                value={newType}
                                onChange={(event) =>
                                    setNewType(event.target.value)
                                }
                                className={inputClass}
                            >
                                {Object.entries(BLOCK_SCHEMAS).map(
                                    ([value, schema]) => (
                                        <option key={value} value={value}>
                                            {schema.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() =>
                                setBlocks([...blocks, { type: newType }])
                            }
                        >
                            <Plus size={16} aria-hidden /> დამატება
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="ml-auto min-h-11"
                            onClick={openAdvanced}
                        >
                            <Code2 size={16} aria-hidden /> JSON რეჟიმი
                        </Button>
                    </div>
                </>
            )}
        </fieldset>
    );
}
