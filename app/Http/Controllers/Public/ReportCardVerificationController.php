<?php

namespace App\Http\Controllers\Public;

use App\Domain\Assessment\Models\ReportCardPublication;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public check that a printed report card is genuine: someone holding the
 * paper can confirm its school, period, revision and whether a newer
 * revision exists. Deliberately shows no marks and only the student's
 * initials — the code proves authenticity, it is not a way to read grades.
 * Scoped to the current tenant's domain, rate-limited, noindex.
 */
class ReportCardVerificationController extends Controller
{
    public function __invoke(CurrentTenant $currentTenant, string $code): Response
    {
        $tenant = $currentTenant->get();

        $publication = ReportCardPublication::query()
            ->where('tenant_id', $tenant->id)
            ->where('verification_code', strtoupper($code))
            ->first();

        if ($publication === null) {
            return Inertia::render('public/report-card-verify', ['result' => null]);
        }

        $latestRevision = (int) ReportCardPublication::query()
            ->where('tenant_id', $tenant->id)
            ->where('report_card_id', $publication->report_card_id)
            ->max('revision');

        $snapshot = $publication->snapshot;

        return Inertia::render('public/report-card-verify', [
            'result' => [
                'schoolName' => $snapshot['schoolName'] ?? null,
                'studentInitials' => $this->initials((string) ($snapshot['studentName'] ?? '')),
                'className' => $snapshot['className'] ?? null,
                'academicYearName' => $snapshot['academicYearName'] ?? null,
                'periodName' => $snapshot['periodName'] ?? null,
                'revision' => $publication->revision,
                'publishedAt' => $publication->published_at->toIso8601String(),
                'isLatest' => $publication->revision === $latestRevision,
            ],
        ]);
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->filter()
            ->map(fn (string $part) => mb_substr($part, 0, 1).'.')
            ->implode(' ');
    }
}
