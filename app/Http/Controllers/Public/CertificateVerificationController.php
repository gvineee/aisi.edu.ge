<?php

namespace App\Http\Controllers\Public;

use App\Domain\Certificates\Models\IssuedCertificate;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public check that a printed certificate, diploma or letter is genuine and
 * still valid: school, kind, title, number, date and the holder's initials
 * only — never the text. Scoped to the current tenant's domain,
 * rate-limited, noindex.
 */
class CertificateVerificationController extends Controller
{
    public function __invoke(CurrentTenant $currentTenant, string $code): Response
    {
        $certificate = IssuedCertificate::query()
            ->where('tenant_id', $currentTenant->get()->id)
            ->where('verification_code', strtoupper($code))
            ->first();

        return Inertia::render('public/certificate-verify', [
            'result' => $certificate === null ? null : [
                'schoolName' => $certificate->snapshot['schoolName'],
                'kind' => $certificate->snapshot['kind'],
                'title' => $certificate->snapshot['title'],
                'number' => $certificate->number(),
                'issuedOn' => $certificate->snapshot['issuedOn'],
                'holderInitials' => collect(preg_split('/\s+/u', trim((string) $certificate->snapshot['recipient'])) ?: [])
                    ->filter()->map(fn (string $part) => mb_substr($part, 0, 1).'.')->implode(' '),
                'isRevoked' => $certificate->revoked_at !== null,
            ],
        ]);
    }
}
