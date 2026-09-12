import { Sun } from 'lucide-react';

type Props = {
    eyebrow: string;
    heading: string;
    date?: string;
    showSun?: boolean;
    trailing?: React.ReactNode;
};

/**
 * design/app/AisiConcept.tsx's `.dashboard-heading`: an eyebrow line, a
 * large heading (with a sun icon on the "today" screen only), and the
 * current date underneath.
 */
export default function DashboardHeading({
    eyebrow,
    heading,
    date,
    showSun = false,
    trailing,
}: Props) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <p className="mb-2 text-xs font-bold tracking-widest text-slate-500">
                    {eyebrow}
                </p>
                <h1 className="flex items-center gap-3 text-xl font-bold sm:text-2xl">
                    {heading}
                    {showSun && (
                        <Sun className="h-6 w-6 shrink-0 text-[#d65227]" />
                    )}
                </h1>
                {date && <p className="mt-1 text-xs text-slate-500">{date}</p>}
            </div>
            {trailing}
        </div>
    );
}
