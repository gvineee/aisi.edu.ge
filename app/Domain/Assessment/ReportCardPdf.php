<?php

namespace App\Domain\Assessment;

use App\Domain\Assessment\Models\ReportCardPublication;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Server-side PDF of a published report card, rendered from the immutable
 * publication snapshot (never from live marks) with the bundled Noto Sans
 * Georgian font (OFL, resources/fonts). Remote resources are disabled and
 * file access is chrooted to the fonts directory.
 */
class ReportCardPdf
{
    public function render(ReportCardPublication $publication, string $verificationUrl, string $timezone): string
    {
        $fontCache = storage_path('framework/cache/dompdf-fonts');
        File::ensureDirectoryExists($fontCache);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot([resource_path('fonts')]);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setDefaultFont('NotoGeorgian');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.report-card', [
            'document' => $publication->snapshot,
            'verificationUrl' => $verificationUrl,
            'timezone' => $timezone,
            'fontRegular' => resource_path('fonts/noto-georgian-regular.ttf'),
            'fontBold' => resource_path('fonts/noto-georgian-bold.ttf'),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
