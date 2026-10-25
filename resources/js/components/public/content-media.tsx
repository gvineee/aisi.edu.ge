import { FileText } from 'lucide-react';

export type ContentMedia = {
    images: {
        url: string;
        alt: string;
        caption: string | null;
        group: string | null;
    }[];
    attachments: { url: string; label: string; mime: string; bytes: number }[];
};

const KIND: Record<string, string> = {
    'application/pdf': 'PDF',
    'application/msword': 'Word',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
        'Word',
    'application/vnd.ms-excel': 'Excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
        'Excel',
};

const size = (bytes: number) =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toLocaleString('ka-GE', { maximumFractionDigits: 1 })} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

/**
 * The images (in their original order, galleries kept together) and file
 * attachments carried over from the original page or post.
 */
export default function ContentMediaBlock({ media }: { media: ContentMedia }) {
    if (media.images.length === 0 && media.attachments.length === 0) {
        return null;
    }

    // Consecutive images of one gallery render as one grid.
    const groups: {
        key: string;
        gallery: boolean;
        images: ContentMedia['images'];
    }[] = [];
    media.images.forEach((image, index) => {
        const last = groups[groups.length - 1];
        if (image.group !== null && last?.key === image.group) {
            last.images.push(image);
        } else {
            groups.push({
                key: image.group ?? `inline-${index}`,
                gallery: image.group !== null,
                images: [image],
            });
        }
    });

    return (
        <div className="mx-auto max-w-3xl space-y-6 px-6 pb-12">
            {groups.map((group) =>
                group.gallery ? (
                    <ul
                        key={group.key}
                        aria-label="გალერეა"
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                    >
                        {group.images.map((image) => (
                            <li key={image.url}>
                                <figure>
                                    <img
                                        src={image.url}
                                        alt={image.alt}
                                        loading="lazy"
                                        className="aspect-square w-full rounded-lg object-cover"
                                    />
                                    {image.caption && (
                                        <figcaption className="mt-1 text-xs text-slate-500">
                                            {image.caption}
                                        </figcaption>
                                    )}
                                </figure>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <figure key={group.key}>
                        <img
                            src={group.images[0].url}
                            alt={group.images[0].alt}
                            loading="lazy"
                            className="w-full rounded-xl object-cover"
                        />
                        {group.images[0].caption && (
                            <figcaption className="mt-2 text-sm text-slate-500">
                                {group.images[0].caption}
                            </figcaption>
                        )}
                    </figure>
                ),
            )}

            {media.attachments.length > 0 && (
                <section aria-labelledby="attachments">
                    <h2 id="attachments" className="mb-3 text-lg">
                        დოკუმენტები
                    </h2>
                    <ul className="space-y-2">
                        {media.attachments.map((file) => (
                            <li key={file.url}>
                                <a
                                    href={file.url}
                                    className="flex min-h-11 items-center gap-3 rounded-lg p-3 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                                >
                                    <FileText
                                        size={18}
                                        aria-hidden
                                        className="shrink-0 text-slate-400"
                                    />
                                    <span className="flex-1">{file.label}</span>
                                    <span className="text-xs text-slate-500">
                                        {KIND[file.mime] ?? 'ფაილი'} ·{' '}
                                        {size(file.bytes)}
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
