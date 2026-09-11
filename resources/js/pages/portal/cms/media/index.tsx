import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Copy, Image as ImageIcon, Upload } from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type MediaItem = {
    id: number;
    path: string;
    url: string;
    mime: string;
    size: number;
    originalFilename: string;
    altText: string | null;
    createdAt: string | null;
};

type Props = {
    media: MediaItem[];
};

function formatSize(bytes: number): string {
    return `${(bytes / 1024).toFixed(0)} KB`;
}

export default function CmsMediaIndex({ media }: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [altText, setAltText] = useState('');
    const [uploading, setUploading] = useState(false);

    const upload = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!file) return;

        const data = new FormData();
        data.append('file', file);
        data.append('alt_text', altText);

        setUploading(true);
        router.post('/portal/cms/media', data, {
            forceFormData: true,
            onSuccess: () => {
                setFile(null);
                setAltText('');
                toast.success('ფაილი აიტვირთა.');
            },
            onError: () => toast.error('ატვირთვა ვერ შესრულდა.'),
            onFinish: () => setUploading(false),
        });
    };

    const copyUrl = (item: MediaItem) => {
        navigator.clipboard?.writeText(item.url).then(
            () => toast.success('URL დაკოპირებულია.'),
            () => toast.error('ვერ დაკოპირდა.'),
        );
    };

    return (
        <PortalLayout>
            <Head title="CMS — მედია" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">მედიაბიბლიოთეკა</h1>
                <div className="flex gap-1">
                    <Link
                        href="/portal/cms/pages"
                        className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium"
                    >
                        გვერდები
                    </Link>
                    <Link
                        href="/portal/cms/posts"
                        className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium"
                    >
                        ამბები
                    </Link>
                </div>
            </div>

            <form
                onSubmit={upload}
                encType="multipart/form-data"
                className="mb-8 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-6"
            >
                <div>
                    <label className="mb-1 block text-sm font-medium">
                        ფაილი (PNG/JPEG/WEBP/PDF, მაქს. 10MB)
                    </label>
                    <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp,application/pdf"
                        onChange={(event) =>
                            setFile(event.target.files?.[0] ?? null)
                        }
                        className="block text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium">
                        Alt-ტექსტი
                    </label>
                    <Input
                        value={altText}
                        onChange={(event) => setAltText(event.target.value)}
                        placeholder="სურათის აღწერა (accessibility)"
                    />
                </div>
                <Button type="submit" disabled={!file || uploading}>
                    <Upload size={16} /> ატვირთვა
                </Button>
            </form>

            {media.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center">
                    <ImageIcon className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                    <p className="font-medium">ჯერ არცერთი ფაილი არ არის.</p>
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {media.map((item) => (
                        <div
                            key={item.id}
                            className="overflow-hidden rounded-xl border border-slate-200 bg-white"
                        >
                            {item.mime.startsWith('image/') ? (
                                <img
                                    src={item.url}
                                    alt={item.altText ?? ''}
                                    className="h-32 w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-32 items-center justify-center bg-slate-100">
                                    <ImageIcon className="h-8 w-8 text-slate-400" />
                                </div>
                            )}
                            <div className="p-3">
                                <p className="truncate text-xs font-medium">
                                    {item.originalFilename}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {formatSize(item.size)}
                                </p>
                                <button
                                    type="button"
                                    onClick={() => copyUrl(item)}
                                    className="mt-2 inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium hover:bg-slate-100"
                                >
                                    <Copy size={12} /> URL კოპირება
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </PortalLayout>
    );
}
