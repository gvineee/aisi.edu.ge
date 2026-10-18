<?php

namespace App\Domain\Finance;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Server-side invoice / receipt PDFs with the bundled Georgian font. Same
 * hardened dompdf setup as the report card PDF: no remote resources, no
 * PHP, file access chrooted to the fonts directory.
 */
class FinancePdf
{
    /**
     * @param  'pdf.invoice'|'pdf.receipt'|'pdf.credit-note'  $view
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        $fontCache = storage_path('framework/cache/dompdf-fonts');
        // Own temp dir: the system one is not always usable (e.g. under
        // `php artisan serve` on Windows), and dompdf writes fonts there first.
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
        $dompdf->loadHtml(view($view, $data + [
            'fontRegular' => resource_path('fonts/noto-georgian-regular.ttf'),
            'fontBold' => resource_path('fonts/noto-georgian-bold.ttf'),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
