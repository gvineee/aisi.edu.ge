import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Info } from 'lucide-react';
import PortalLayout from '@/layouts/portal/portal-layout';
import { formatDateTime, formatDay } from '@/lib/assessment';

type Metric = {
    key: string;
    label: string;
    value: number | null;
    status: 'ok' | 'no_data';
    unit?: string | null;
    numerator: number | null;
    denominator: number | null;
    definition: string;
    period: string;
    drilldown: string | null;
};

type Props = {
    computedAt: string;
    metrics: Metric[];
    attendanceTrend: { date: string; rate: number; records: number }[];
};

function TrendChart({ points }: { points: Props['attendanceTrend'] }) {
    const width = 560;
    const height = 160;
    const pad = 24;
    const step =
        points.length > 1 ? (width - pad * 2) / (points.length - 1) : 0;
    const y = (rate: number) =>
        height - pad - (rate / 100) * (height - pad * 2);
    const path = points
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${pad + i * step},${y(p.rate)}`)
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            className="h-40 w-full"
            role="img"
            aria-labelledby="trend-title"
        >
            <title id="trend-title">
                დასწრების მაჩვენებელი ბოლო დღეებში (დეტალები ცხრილშია ქვემოთ)
            </title>
            {[0, 50, 100].map((tick) => (
                <g key={tick}>
                    <line
                        x1={pad}
                        x2={width - pad}
                        y1={y(tick)}
                        y2={y(tick)}
                        stroke="currentColor"
                        className="text-slate-200"
                    />
                    <text
                        x={2}
                        y={y(tick) + 4}
                        className="fill-slate-500 text-[10px]"
                    >
                        {tick}%
                    </text>
                </g>
            ))}
            <path
                d={path}
                fill="none"
                stroke="var(--brand-accent)"
                strokeWidth={2.5}
            />
            {points.map((p, i) => (
                <circle
                    key={p.date}
                    cx={pad + i * step}
                    cy={y(p.rate)}
                    r={3.5}
                    fill="var(--brand-primary)"
                />
            ))}
        </svg>
    );
}

/**
 * Director pulse. Each card shows its definition, period and denominator;
 * a metric without data says so instead of showing an invented number.
 */
export default function SchoolPulsePage({
    computedAt,
    metrics,
    attendanceTrend,
}: Props) {
    return (
        <PortalLayout>
            <Head title="სკოლის პულსი" />
            <h1 className="mb-1 text-2xl">სკოლის პულსი</h1>
            <p className="mb-6 text-sm text-slate-500">
                გამოთვლილია {formatDateTime(computedAt)}
            </p>

            <ul className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {metrics.map((m) => (
                    <li
                        key={m.key}
                        className="flex flex-col gap-2 rounded-2xl bg-white p-5 ring-1 ring-slate-200"
                    >
                        <p className="text-sm text-slate-600">{m.label}</p>
                        <p className="text-3xl font-semibold">
                            {m.status === 'no_data' || m.value === null ? (
                                <span className="text-base font-normal text-slate-500">
                                    მონაცემი ჯერ არ არის
                                </span>
                            ) : (
                                <>
                                    {m.value.toLocaleString('ka-GE')}
                                    {m.unit ?? ''}
                                </>
                            )}
                        </p>
                        {m.denominator !== null && m.status === 'ok' && (
                            <p className="text-xs text-slate-500">
                                {m.numerator} / {m.denominator}
                            </p>
                        )}
                        <details className="text-xs text-slate-600">
                            <summary className="flex min-h-11 cursor-pointer items-center gap-1">
                                <Info size={14} aria-hidden /> როგორ ითვლება ·{' '}
                                {m.period}
                            </summary>
                            <p className="mt-1">{m.definition}</p>
                        </details>
                        {m.drilldown && (
                            <Link
                                href={m.drilldown}
                                className="mt-auto inline-flex min-h-11 items-center gap-1 text-sm font-medium underline-offset-4 hover:underline"
                            >
                                დეტალურად <ArrowRight size={14} aria-hidden />
                            </Link>
                        )}
                    </li>
                ))}
            </ul>

            <section
                className="rounded-2xl bg-white p-5 ring-1 ring-slate-200"
                aria-labelledby="trend-heading"
            >
                <h2 id="trend-heading" className="mb-1 text-lg">
                    დასწრების ტრენდი
                </h2>
                <p className="mb-3 text-xs text-slate-500">
                    ბოლო 14 დღე; ნაჩვენებია მხოლოდ დღეები, როცა დასწრება
                    მონიშნულია. პროგნოზი არ კეთდება.
                </p>
                {attendanceTrend.length === 0 ? (
                    <p className="text-sm text-slate-500">
                        ამ პერიოდში დასწრება მონიშნული არ არის.
                    </p>
                ) : (
                    <>
                        <TrendChart points={attendanceTrend} />
                        <table className="mt-3 w-full text-sm">
                            <caption className="sr-only">
                                დასწრება დღეების მიხედვით
                            </caption>
                            <thead>
                                <tr className="text-left text-slate-500">
                                    <th scope="col" className="py-1">
                                        დღე
                                    </th>
                                    <th scope="col" className="py-1">
                                        დასწრება
                                    </th>
                                    <th scope="col" className="py-1">
                                        ჩანაწერი
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {attendanceTrend.map((p) => (
                                    <tr
                                        key={p.date}
                                        className="border-t border-slate-100"
                                    >
                                        <td className="py-1">
                                            {formatDay(p.date)}
                                        </td>
                                        <td className="py-1">{p.rate}%</td>
                                        <td className="py-1">{p.records}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}
            </section>
        </PortalLayout>
    );
}
