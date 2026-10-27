<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Certificates\IssueCertificates;
use App\Domain\Certificates\Models\CertificateTemplate;
use App\Domain\Certificates\Models\IssuedCertificate;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * „ცნობები და დიპლომები“: templates, issuing (one pupil, a whole class, or
 * a letter to anyone), the numbered register, revoking and the PDF.
 * Management only — the register names pupils.
 */
class CertificateController extends Controller
{
    /** @var list<string> */
    public const ACCESS_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function __construct(private readonly IssueCertificates $certificates) {}

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenantId = $this->authorize($request, $currentTenant);
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $year = AcademicYear::query()->where('tenant_id', $tenantId)->where('is_current', true)->first();
        $classes = $year === null ? collect() : SchoolClass::query()->where('tenant_id', $tenantId)->where('academic_year_id', $year->id)->get()->sortBy('name', SORT_NATURAL)->values();

        // A search is a name, a verification code or a number like 2026/0012.
        $number = preg_match('#^(\d{4})/(\d+)$#', $search, $m) === 1 ? [(int) $m[1], (int) $m[2]] : null;
        $issued = IssuedCertificate::query()->where('tenant_id', $tenantId)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('snapshot->recipient', 'like', "%{$search}%")
                ->orWhere('verification_code', strtoupper($search))
                ->when($number !== null, fn ($n) => $n->orWhere(fn ($w) => $w->where('year', $number[0] ?? 0)->where('sequence', $number[1] ?? 0)))))
            ->latest('id')->limit(200)->get();

        return Inertia::render('portal/certificates/index', [
            'templates' => CertificateTemplate::query()->where('tenant_id', $tenantId)->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (CertificateTemplate $t) => $t->only(['id', 'name', 'kind', 'title', 'body', 'signatory', 'orientation', 'is_active']))->values(),
            'classes' => $classes->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'students' => Student::query()->where('tenant_id', $tenantId)->whereIn('school_class_id', $classes->pluck('id'))->where('is_active', true)
                ->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName(), 'classId' => $s->school_class_id])->values(),
            'issued' => $issued->map(fn (IssuedCertificate $c) => [
                'id' => $c->id,
                'number' => $c->number(),
                'kind' => $c->snapshot['kind'],
                'title' => $c->snapshot['title'],
                'recipient' => $c->snapshot['recipient'],
                'className' => $c->snapshot['className'],
                'issuedOn' => $c->snapshot['issuedOn'],
                'code' => $c->verification_code,
                'revokedAt' => $c->revoked_at?->toIso8601String(),
                'revokeReason' => $c->revoke_reason,
            ])->values(),
            'search' => $search,
            'placeholders' => CertificateTemplate::PLACEHOLDERS,
        ]);
    }

    public function saveTemplate(Request $request, CurrentTenant $currentTenant, ?CertificateTemplate $certificateTemplate = null): RedirectResponse
    {
        $tenantId = $this->authorize($request, $currentTenant);
        abort_if($certificateTemplate !== null && $certificateTemplate->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(CertificateTemplate::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'signatory' => ['nullable', 'string', 'max:160'],
            'orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'is_active' => ['boolean'],
        ]);

        $template = $certificateTemplate ?? tap(new CertificateTemplate(['created_by' => $request->user()->id]), fn (CertificateTemplate $t) => $t->tenant_id = $tenantId);
        $template->fill($data + ['is_active' => true])->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'შაბლონი შენახულია.']);
    }

    public function issue(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorize($request, $currentTenant);
        $data = $request->validate([
            'certificate_template_id' => ['required', 'integer', Rule::exists('certificate_templates', 'id')->where('tenant_id', $tenant->id)],
            'mode' => ['required', Rule::in(['student', 'class', 'recipient'])],
            'student_id' => ['required_if:mode,student', 'nullable', 'integer', Rule::exists('students', 'id')->where('tenant_id', $tenant->id)],
            'school_class_id' => ['required_if:mode,class', 'nullable', 'integer', Rule::exists('school_classes', 'id')->where('tenant_id', $tenant->id)],
            'recipient' => ['required_if:mode,recipient', 'nullable', 'string', 'max:200'],
        ]);

        $template = CertificateTemplate::query()->where('tenant_id', $tenant->id)->findOrFail((int) $data['certificate_template_id']);
        $students = match ($data['mode']) {
            'student' => [Student::query()->where('tenant_id', $tenant->id)->findOrFail((int) $data['student_id'])],
            'class' => array_values(Student::query()->where('tenant_id', $tenant->id)->where('school_class_id', (int) $data['school_class_id'])->where('is_active', true)
                ->orderBy('last_name')->orderBy('first_name')->get()->all()),
            default => [],
        };

        if ($data['mode'] === 'class' && $students === []) {
            return back()->withErrors(['school_class_id' => 'ამ კლასში აქტიური მოსწავლე არ არის.']);
        }

        $issued = $this->certificates->issue($tenant, $template, $students, $data['mode'] === 'recipient' ? $data['recipient'] : null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => count($issued) === 1 ? "გაიცა № {$issued[0]->number()}." : 'გაიცა '.count($issued).' დოკუმენტი.']);
    }

    public function revoke(Request $request, CurrentTenant $currentTenant, IssuedCertificate $issuedCertificate): RedirectResponse
    {
        $tenantId = $this->authorize($request, $currentTenant);
        abort_unless($issuedCertificate->tenant_id === $tenantId, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->certificates->revoke($issuedCertificate, trim($reason), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => "№ {$issuedCertificate->number()} გაუქმდა."]);
    }

    public function download(Request $request, CurrentTenant $currentTenant, IssuedCertificate $issuedCertificate, AuditLogger $auditLogger): HttpResponse
    {
        $tenantId = $this->authorize($request, $currentTenant);
        abort_unless($issuedCertificate->tenant_id === $tenantId, 404);
        $auditLogger->record($tenantId, 'certificate.downloaded', $issuedCertificate, $request->user()->id, ['number' => $issuedCertificate->number()]);
        $pdf = $this->certificates->pdf($issuedCertificate, route('certificates.verify', $issuedCertificate->verification_code));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document-'.$issuedCertificate->year.'-'.$issuedCertificate->sequence.'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function authorize(Request $request, CurrentTenant $currentTenant): int
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);

        return $tenantId;
    }
}
