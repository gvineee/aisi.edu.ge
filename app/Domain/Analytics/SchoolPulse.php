<?php

namespace App\Domain\Analytics;

use App\Domain\Assessment\Models\ReportCard;
use App\Domain\Documents\Actions\ListPendingApprovalRequests;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Learning\Models\AttendanceRecord;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Director "სკოლის პულსი" metrics (CLAUDE-PLATFORM-MODULES §13). Every
 * metric states its definition, period, denominator and computation time;
 * none falls back to an invented number — with no data it says so. Only
 * school-level aggregates are returned: no health data and no individual
 * student rows. Queries run live (correctness first); aggregate tables
 * would only be added after profiling.
 */
class SchoolPulse
{
    public function __construct(private readonly ListPendingApprovalRequests $pendingApprovals) {}

    /**
     * @return array{computedAt: string, metrics: array<int, array<string, mixed>>, attendanceTrend: array<int, array{date: string, rate: float, records: int}>}
     */
    public function compute(int $tenantId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now('Asia/Tbilisi');
        $today = $now->toDateString();

        $todayCounts = $this->attendanceCounts($tenantId, $today, $today);
        $marked = array_sum($todayCounts);
        $attending = ($todayCounts['present'] ?? 0) + ($todayCounts['late'] ?? 0);

        $absentStudents = AttendanceRecord::query()
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('occurred_on', $today)
            ->where('status', 'absent')
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->where('students.is_active', true)
            ->distinct()
            ->count('attendance_records.student_id');

        $pending = $this->pendingApprovals->handle($tenantId)->count();
        $reportCardsAwaiting = ReportCard::query()->where('tenant_id', $tenantId)->where('status', ReportCard::STATUS_HOMEROOM_REVIEW)->count();

        $period = 'დღეს, '.$now->format('d.m.Y').' (Asia/Tbilisi)';

        return [
            'computedAt' => Carbon::now()->toIso8601String(),
            'metrics' => [
                $this->ratio('attendance_today', 'დღევანდელი დასწრება', $attending, $marked, $period,
                    'დღეს მონიშნული გაკვეთილ-დასწრებებიდან რამდენი იყო „დაესწრო" ან „დააგვიანა". მნიშვნელი = დღეს მონიშნული ჩანაწერები (აქტიური მოსწავლეები).',
                    null),
                $this->ratio('lateness_today', 'დაგვიანება დღეს', $todayCounts['late'] ?? 0, $marked, $period,
                    'დღეს მონიშნული გაკვეთილ-დასწრებებიდან „დააგვიანა"-ს წილი.', null),
                $this->count('absent_students_today', 'დღეს არმყოფი მოსწავლე', $absentStudents, $period,
                    'აქტიური მოსწავლეები, რომლებსაც დღეს ერთ გაკვეთილზე მაინც „არ დაესწრო" აქვთ.', null),
                $this->count('approval_queue', 'დასამტკიცებელი დოკუმენტი', $pending, 'ამ წუთისთვის',
                    'დოკუმენტების ცენტრში დირექტორის გადაწყვეტილების მოლოდინში მყოფი მოთხოვნები.', '/documents/director-worklist'),
                $this->count('report_cards_awaiting', 'დასამტკიცებელი ტაბელი', $reportCardsAwaiting, 'ამ წუთისთვის',
                    'დამრიგებლის მიერ განხილული და დირექტორის დამტკიცების მოლოდინში მყოფი ტაბელები.', '/portal/report-cards'),
                $this->collection($tenantId, $now),
            ],
            'attendanceTrend' => $this->trend($tenantId, $now),
        ];
    }

    /**
     * Share of this month's invoiced amount (non-void invoices issued this
     * month) that has been paid. "No data" while finance is off or nothing
     * was invoiced — never a guessed figure.
     *
     * @return array<string, mixed>
     */
    private function collection(int $tenantId, Carbon $now): array
    {
        $period = 'მიმდინარე თვე ('.$now->format('m.Y').')';
        $definition = 'მიმდინარე თვეში გამოწერილი (გაუქმებულის გარდა) ინვოისების ჯამიდან გადახდილი წილი. ფინანსური მოდული ჯერ Sandbox რეჟიმშია.';

        if (! FeatureEntitlement::isEnabled($tenantId, FeatureEntitlement::FEATURE_FINANCE)) {
            return ['key' => 'collection_current', 'label' => 'მიმდინარე შემოსავალი', 'value' => null, 'status' => 'no_data', 'unit' => '%',
                'numerator' => null, 'denominator' => null, 'definition' => 'ფინანსური მოდული ჩართული არ არის — მაჩვენებელი არ ითვლება.', 'period' => $period, 'drilldown' => null];
        }

        $invoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereDate('issued_on', '>=', $now->copy()->startOfMonth()->toDateString())
            ->whereDate('issued_on', '<=', $now->copy()->endOfMonth()->toDateString())
            ->get(['total_minor', 'paid_minor']);

        $metric = $this->ratio('collection_current', 'მიმდინარე შემოსავალი', (int) $invoices->sum('paid_minor'), (int) $invoices->sum('total_minor'), $period, $definition, '/portal/finance');
        $metric['moneyUnit'] = 'minor';

        return $metric;
    }

    /**
     * @return array<string, int>
     */
    private function attendanceCounts(int $tenantId, string $from, string $to): array
    {
        return AttendanceRecord::query()
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('occurred_on', '>=', $from)
            ->whereDate('occurred_on', '<=', $to)
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->where('students.is_active', true)
            ->select('attendance_records.status', DB::raw('count(*) as aggregate'))
            ->groupBy('attendance_records.status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Attendance rate per day for the last 14 calendar days that have any
     * marked records — days without records are omitted, not shown as 0.
     *
     * @return array<int, array{date: string, rate: float, records: int}>
     */
    private function trend(int $tenantId, Carbon $now): array
    {
        $rows = AttendanceRecord::query()
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('occurred_on', '>=', $now->copy()->subDays(13)->toDateString())
            ->whereDate('occurred_on', '<=', $now->toDateString())
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->where('students.is_active', true)
            ->get(['attendance_records.occurred_on', 'attendance_records.status']);

        return $rows
            ->groupBy(fn (AttendanceRecord $record) => Carbon::parse($record->occurred_on)->toDateString())
            ->map(fn ($group, string $date) => [
                'date' => $date,
                'records' => $group->count(),
                'rate' => round($group->whereIn('status', ['present', 'late'])->count() / $group->count() * 100, 1),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ratio(string $key, string $label, int $numerator, int $denominator, string $period, string $definition, ?string $drilldown): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $denominator === 0 ? null : round($numerator / $denominator * 100, 1),
            'status' => $denominator === 0 ? 'no_data' : 'ok',
            'unit' => '%',
            'numerator' => $numerator,
            'denominator' => $denominator,
            'definition' => $definition,
            'period' => $period,
            'drilldown' => $drilldown,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function count(string $key, string $label, int $value, string $period, string $definition, ?string $drilldown): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'status' => 'ok',
            'unit' => null,
            'numerator' => null,
            'denominator' => null,
            'definition' => $definition,
            'period' => $period,
            'drilldown' => $drilldown,
        ];
    }
}
