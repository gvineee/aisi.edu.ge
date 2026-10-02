import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';

type Row = { studentName: string; className: string | null };

type Props = {
    club: { id: number; name: string; capacity: number };
    enrolled: Row[];
    waitlist: Row[];
};

export default function ClubRoster({ club, enrolled, waitlist }: Props) {
    return (
        <PortalLayout>
            <Head title={`${club.name} — მონაწილეები`} />
            <Link
                href="/portal/clubs"
                className="mb-4 inline-flex min-h-11 items-center gap-2 text-sm text-slate-600 hover:text-slate-900"
            >
                <ArrowLeft size={16} /> კლუბები
            </Link>
            <h1 className="mb-6 text-2xl">{club.name}</h1>
            <div className="grid gap-6 md:grid-cols-2">
                <section>
                    <h2 className="mb-2 text-lg">
                        ჩარიცხულები ({enrolled.length}/{club.capacity})
                    </h2>
                    <ol className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                        {enrolled.length === 0 && (
                            <li className="p-3 text-slate-500">ჯერ არავინ.</li>
                        )}
                        {enrolled.map((r, i) => (
                            <li key={i} className="flex justify-between p-3">
                                <span>{r.studentName}</span>
                                <span className="text-slate-500">
                                    {r.className}
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>
                <section>
                    <h2 className="mb-2 text-lg">რიგი ({waitlist.length})</h2>
                    <ol className="divide-y divide-slate-100 rounded-xl bg-white text-sm ring-1 ring-slate-200">
                        {waitlist.length === 0 && (
                            <li className="p-3 text-slate-500">
                                რიგი ცარიელია.
                            </li>
                        )}
                        {waitlist.map((r, i) => (
                            <li key={i} className="flex justify-between p-3">
                                <span>
                                    {i + 1}. {r.studentName}
                                </span>
                                <span className="text-slate-500">
                                    {r.className}
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </PortalLayout>
    );
}
