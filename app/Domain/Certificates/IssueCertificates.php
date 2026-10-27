<?php

namespace App\Domain\Certificates;

use App\Domain\Academics\Models\Student;
use App\Domain\Certificates\Models\CertificateTemplate;
use App\Domain\Certificates\Models\IssuedCertificate;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Issues documents from a template — to one pupil, to a whole class, or
 * as a letter without a pupil — each with the next number of the year and
 * a verification code; revokes them; renders their PDF from the snapshot.
 */
class IssueCertificates
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<Student>  $students  empty for a letter with a free-text recipient
     * @return list<IssuedCertificate>
     */
    public function issue(Tenant $tenant, CertificateTemplate $template, array $students, ?string $recipient, User $actor): array
    {
        if (! $template->is_active) {
            throw ValidationException::withMessages(['certificate_template_id' => 'შაბლონი გამორთულია.']);
        }

        if ($students === [] && ($recipient === null || trim($recipient) === '')) {
            throw ValidationException::withMessages(['student_id' => 'აირჩიეთ მოსწავლე, კლასი ან მიუთითეთ ადრესატი.']);
        }

        $now = Carbon::now($tenant->timezone);

        $issued = DB::transaction(function () use ($tenant, $template, $students, $recipient, $actor, $now) {
            // Lock the year's numbering so two issuers never share a number.
            $sequence = (int) IssuedCertificate::query()->where('tenant_id', $tenant->id)->where('year', $now->year)->lockForUpdate()->max('sequence');
            $rows = [];

            foreach ($students === [] ? [null] : $students as $student) {
                $sequence++;
                $student?->loadMissing('schoolClass.academicYear');
                $number = sprintf('%d/%04d', $now->year, $sequence);
                $values = [
                    'name' => $student?->fullName() ?? trim((string) $recipient),
                    'class' => $student?->schoolClass->name ?? '',
                    'year' => $student?->schoolClass?->academicYear->name ?? '',
                    'date' => $now->format('d.m.Y'),
                    'school' => $tenant->name,
                    'number' => $number,
                ];

                $certificate = new IssuedCertificate([
                    'certificate_template_id' => $template->id,
                    'student_id' => $student?->id,
                    'year' => $now->year,
                    'sequence' => $sequence,
                    'verification_code' => $this->uniqueCode(),
                    'snapshot' => [
                        'kind' => $template->kind,
                        'title' => $this->fill($template->title, $values),
                        'body' => $this->fill($template->body, $values),
                        'signatory' => $template->signatory,
                        'orientation' => $template->orientation,
                        'schoolName' => $tenant->name,
                        'recipient' => $values['name'],
                        'className' => $student?->schoolClass?->name,
                        'number' => $number,
                        'issuedOn' => $now->toDateString(),
                    ],
                    'issued_by' => $actor->id,
                    'issued_at' => now(),
                ]);
                $certificate->tenant_id = $tenant->id;
                $certificate->save();
                $rows[] = $certificate;
            }

            return $rows;
        });

        $this->auditLogger->record($tenant->id, 'certificate.issued', $template, $actor->id, [
            'count' => count($issued),
            'numbers' => array_map(fn (IssuedCertificate $c) => $c->number(), $issued),
        ]);

        return $issued;
    }

    public function revoke(IssuedCertificate $certificate, string $reason, User $actor): void
    {
        if ($certificate->revoked_at !== null) {
            throw ValidationException::withMessages(['reason' => 'დოკუმენტი უკვე გაუქმებულია.']);
        }

        $certificate->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason]);
        $this->auditLogger->record($certificate->tenant_id, 'certificate.revoked', $certificate, $actor->id, ['number' => $certificate->number()]);
    }

    public function pdf(IssuedCertificate $certificate, string $verificationUrl): string
    {
        $fontCache = storage_path('framework/cache/dompdf-fonts');
        $tempDir = storage_path('framework/cache/dompdf-tmp');
        File::ensureDirectoryExists($fontCache);
        File::ensureDirectoryExists($tempDir);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot([resource_path('fonts')]);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setTempDir($tempDir);
        $options->setDefaultFont('NotoGeorgian');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.certificate', [
            'document' => $certificate->snapshot,
            'revoked' => $certificate->revoked_at !== null,
            'verificationUrl' => $verificationUrl,
            'verificationCode' => $certificate->verification_code,
            'fontRegular' => resource_path('fonts/noto-georgian-regular.ttf'),
            'fontBold' => resource_path('fonts/noto-georgian-bold.ttf'),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4', $certificate->snapshot['orientation'] === 'landscape' ? 'landscape' : 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $text, array $values): string
    {
        return strtr($text, array_combine(array_map(fn (string $key) => '{'.$key.'}', array_keys($values)), array_values($values)));
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 12; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (IssuedCertificate::query()->withoutGlobalScopes()->where('verification_code', $code)->exists());

        return $code;
    }
}
