import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Teacher = {
    id: number;
    slug: string;
    name: string;
    subject: string;
    bio: string | null;
    photoUrl: string | null;
    displayOrder: number;
    status: string;
};

type Props = {
    teacher: Teacher | null;
};

export default function TeacherEdit({ teacher }: Props) {
    const [name, setName] = useState(teacher?.name ?? '');
    const [subject, setSubject] = useState(teacher?.subject ?? '');
    const [bio, setBio] = useState(teacher?.bio ?? '');
    const [displayOrder, setDisplayOrder] = useState(
        teacher?.displayOrder ?? 0,
    );
    const [photo, setPhoto] = useState<File | null>(null);
    const [saving, setSaving] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);

        const data = new FormData();
        data.append('name', name);
        data.append('subject', subject);
        if (bio) data.append('bio', bio);
        data.append('display_order', String(displayOrder));
        if (photo) data.append('photo', photo);

        const url = teacher
            ? `/portal/teachers/${teacher.id}`
            : '/portal/teachers';

        if (teacher) {
            data.append('_method', 'put');
        }

        router.post(url, data, {
            forceFormData: true,
            onSuccess: () => {
                toast.success('შენახულია.');
                setPhoto(null);
            },
            onError: () => toast.error('ვერ შეინახა — გადაამოწმეთ ველები.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <PortalLayout>
            <Head title={teacher ? teacher.name : 'ახალი მასწავლებელი'} />

            <div className="mx-auto max-w-lg">
                <h1 className="mb-6 text-2xl">
                    {teacher ? teacher.name : 'ახალი მასწავლებელი'}
                </h1>

                <form onSubmit={submit} className="space-y-5">
                    <div className="flex items-center gap-4">
                        {(photo || teacher?.photoUrl) && (
                            <img
                                src={
                                    photo
                                        ? URL.createObjectURL(photo)
                                        : (teacher?.photoUrl ?? undefined)
                                }
                                alt=""
                                className="h-16 w-16 rounded-full object-cover"
                            />
                        )}
                        <div className="flex-1 space-y-1.5">
                            <Label htmlFor="photo">ფოტო (არასავალდებულო)</Label>
                            <input
                                id="photo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) =>
                                    setPhoto(event.target.files?.[0] ?? null)
                                }
                                className="w-full text-sm"
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="name">სახელი და გვარი</Label>
                        <Input
                            id="name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            required
                            maxLength={255}
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="subject">საგანი / პოზიცია</Label>
                        <Input
                            id="subject"
                            value={subject}
                            onChange={(event) =>
                                setSubject(event.target.value)
                            }
                            required
                            maxLength={255}
                            placeholder="მაგ. ინგლისური ენა"
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="bio">მოკლე ბიოგრაფია</Label>
                        <textarea
                            id="bio"
                            value={bio}
                            onChange={(event) => setBio(event.target.value)}
                            className="min-h-28 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="display_order">
                            რიგითობა (მთავარ გვერდზე ჩვენებისას)
                        </Label>
                        <Input
                            id="display_order"
                            type="number"
                            min={0}
                            value={displayOrder}
                            onChange={(event) =>
                                setDisplayOrder(Number(event.target.value))
                            }
                        />
                    </div>

                    <Button type="submit" disabled={saving} className="w-full">
                        შენახვა
                    </Button>
                </form>
            </div>
        </PortalLayout>
    );
}
